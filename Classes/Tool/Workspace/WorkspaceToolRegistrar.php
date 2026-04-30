<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Workspace;

use Doctrine\DBAL\ParameterType;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use const JSON_THROW_ON_ERROR;

readonly class WorkspaceToolRegistrar
{
    private const string WORKSPACE_TABLE = 'sys_workspace';

    private const array WORKSPACE_LIST_FIELDS = [
        'uid',
        'title',
        'description',
        'adminusers',
        'members',
        'db_mountpoints',
        'file_mountpoints',
        'freeze',
        'live_edit',
        'custom_stages',
    ];

    public function __construct(
        private RecordService $recordService,
        private DataHandlerService $dataHandlerService,
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {
    }

    public function register(Builder $builder): void
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return;
        }

        $this->registerListTool($builder);
        $this->registerGetTool($builder);
        $this->registerSwitchTool($builder);
        $this->registerChangesListTool($builder);
        $this->registerPublishTool($builder);
        $this->registerDiscardTool($builder);
        $this->registerStageSetTool($builder);
    }

    private function registerListTool(Builder $builder): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function () use ($recordService, $logger): string {
                try {
                    $beUser = self::requireBackendUser();

                    $result = $recordService->search(
                        self::WORKSPACE_TABLE,
                        [],
                        500,
                        0,
                        self::WORKSPACE_LIST_FIELDS,
                        null,
                        'uid',
                        'ASC',
                    );

                    $accessible = [];
                    // Live workspace is implicitly accessible
                    $accessible[] = ['uid' => 0, 'title' => 'Live workspace', 'access' => 'online'];

                    foreach ($result['records'] as $row) {
                        /** @var int|string $rawUid */
                        $rawUid = $row['uid'] ?? 0;
                        $uid = (int) $rawUid;
                        // @phpstan-ignore method.internal
                        $access = $beUser->checkWorkspace($uid);
                        if ($access === false) {
                            continue;
                        }
                        /** @var string $rawTitle */
                        $rawTitle = $row['title'] ?? '';
                        /** @var string $rawAccess */
                        $rawAccess = $access['_ACCESS'] ?? '';
                        $accessible[] = [
                            'uid' => $uid,
                            'title' => $rawTitle,
                            'access' => $rawAccess,
                        ];
                    }

                    return json_encode($accessible, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    $logger->error('workspace list tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }
            },
            name: 'workspace_list',
            description: 'List workspaces accessible to the current backend user, including the implicit live workspace (uid 0).'
                . ' Returns uid, title, and access level (online, member, reviewer, owner, admin).',
        );
    }

    private function registerGetTool(Builder $builder): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (int $workspaceId) use ($recordService, $logger): string {
                try {
                    $beUser = self::requireBackendUser();
                    // @phpstan-ignore method.internal
                    $access = $beUser->checkWorkspace($workspaceId);
                    if ($access === false) {
                        return json_encode(['error' => 'Workspace not accessible to current user'], JSON_THROW_ON_ERROR);
                    }

                    /** @var string $accessLabel */
                    $accessLabel = $access['_ACCESS'] ?? '';

                    if ($workspaceId === 0) {
                        return json_encode([
                            'uid' => 0,
                            'title' => 'Live workspace',
                            'access' => $accessLabel !== '' ? $accessLabel : 'online',
                        ], JSON_THROW_ON_ERROR);
                    }

                    $record = $recordService->findByUid(self::WORKSPACE_TABLE, $workspaceId, self::WORKSPACE_LIST_FIELDS);
                    if ($record === null) {
                        return json_encode(['error' => 'Workspace not found'], JSON_THROW_ON_ERROR);
                    }

                    $record['access'] = $accessLabel;

                    return json_encode($record, JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    $logger->error('workspace get tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }
            },
            name: 'workspace_get',
            description: 'Get workspace metadata by uid. Returns title, custom_stages flag, and current user access level.'
                . ' Use uid 0 for the live workspace.',
        );
    }

    private function registerSwitchTool(Builder $builder): void
    {
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (int $workspaceId) use ($logger): RecordUpdatedResult|ErrorResult {
                try {
                    $beUser = self::requireBackendUser();
                    // @phpstan-ignore method.internal
                    $access = $beUser->checkWorkspace($workspaceId);
                    if ($access === false) {
                        return new ErrorResult('Workspace not accessible to current user', ['workspaceId' => $workspaceId]);
                    }

                    // setWorkspace persists to be_users.workspace_id and falls back to default if invalid.
                    // @phpstan-ignore method.internal
                    $beUser->setWorkspace($workspaceId);

                    // @phpstan-ignore property.internal
                    $userArr = $beUser->user;
                    $userUid = 0;
                    if (is_array($userArr)) {
                        /** @var int|string $rawUid */
                        $rawUid = $userArr['uid'] ?? 0;
                        $userUid = (int) $rawUid;
                    }

                    return new RecordUpdatedResult($userUid, ['workspace_id']);
                } catch (\Throwable $e) {
                    $logger->error('workspace switch tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }
            },
            name: 'workspace_switch',
            description: 'Switch the active workspace for the current backend user. Persists to be_users.workspace_id.'
                . ' Subsequent record reads/writes will use the new workspace context. Use 0 for the live workspace.',
        );
    }

    private function registerChangesListTool(Builder $builder): void
    {
        $connectionPool = $this->connectionPool;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (string $table = '', int $limit = 100) use ($connectionPool, $logger): string {
                try {
                    $beUser = self::requireBackendUser();
                    $workspaceId = (int) $beUser->workspace;

                    if ($workspaceId === 0) {
                        return json_encode(['workspaceId' => 0, 'tables' => []], JSON_THROW_ON_ERROR);
                    }

                    $tables = self::workspaceAwareTables();
                    if ($table !== '') {
                        $tables = in_array($table, $tables, true) ? [$table] : [];
                    }

                    $limit = min(max($limit, 1), 500);
                    $changes = [];

                    foreach ($tables as $tableName) {
                        $queryBuilder = $connectionPool->getQueryBuilderForTable($tableName);
                        $queryBuilder->getRestrictions()->removeAll();

                        $rows = $queryBuilder
                            ->select('uid', 'pid', 't3ver_oid', 't3ver_state', 't3ver_stage', 't3ver_wsid')
                            ->from($tableName)
                            ->where(
                                $queryBuilder->expr()->eq(
                                    't3ver_wsid',
                                    $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER),
                                ),
                            )
                            ->setMaxResults($limit)
                            ->orderBy('uid', 'DESC')
                            ->executeQuery()
                            ->fetchAllAssociative();

                        if ($rows === []) {
                            continue;
                        }

                        $changes[$tableName] = array_map(
                            static function (array $row): array {
                                /** @var int|string $uid */
                                $uid = $row['uid'] ?? 0;
                                /** @var int|string $pid */
                                $pid = $row['pid'] ?? 0;
                                /** @var int|string $oid */
                                $oid = $row['t3ver_oid'] ?? 0;
                                /** @var int|string $state */
                                $state = $row['t3ver_state'] ?? 0;
                                /** @var int|string $stage */
                                $stage = $row['t3ver_stage'] ?? 0;

                                return [
                                    'uid' => (int) $uid,
                                    'pid' => (int) $pid,
                                    'liveUid' => (int) $oid,
                                    'state' => self::stateLabel((int) $state),
                                    'stage' => (int) $stage,
                                ];
                            },
                            $rows,
                        );
                    }

                    return json_encode(['workspaceId' => $workspaceId, 'tables' => $changes], JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    $logger->error('workspace changes list tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }
            },
            name: 'workspace_changes_list',
            description: 'List records modified in the current workspace, grouped by table.'
                . ' Optional table filter restricts to a single workspace-aware table.'
                . ' Each record includes uid (workspace version), liveUid (t3ver_oid, 0 for new placeholders),'
                . ' state (default, new, deletePlaceholder, movePointer), and stage (-10/-20/0/custom).',
        );
    }

    private function registerPublishTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $connectionPool = $this->connectionPool;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (
                string $table,
                int $workspaceVersionUid,
            ) use (
                $dataHandlerService,
                $connectionPool,
                $logger
            ): RecordUpdatedResult|ErrorResult {
                try {
                    $row = self::loadVersionRow($connectionPool, $table, $workspaceVersionUid);
                    if ($row === null) {
                        return new ErrorResult('Workspace version not found', ['table' => $table, 'uid' => $workspaceVersionUid]);
                    }

                    /** @var int|string $rawOid */
                    $rawOid = $row['t3ver_oid'] ?? 0;
                    $liveUid = (int) $rawOid;
                    $cmdKey = $liveUid > 0 ? $liveUid : $workspaceVersionUid;

                    $dataHandlerService->processCommand([
                        $table => [
                            $cmdKey => [
                                'version' => [
                                    'action' => 'swap',
                                    'swapWith' => $workspaceVersionUid,
                                ],
                            ],
                        ],
                    ]);

                    return new RecordUpdatedResult($cmdKey, ['published']);
                } catch (\Throwable $e) {
                    $logger->error('workspace publish tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }
            },
            name: 'workspace_publish',
            description: 'Publish a workspace version to live (swap). Pass table and workspaceVersionUid.'
                . ' For new placeholders (t3ver_oid=0) the workspace version becomes the live record.'
                . ' Requires cms-workspaces extension to handle the version=swap cmdmap action.',
        );
    }

    private function registerDiscardTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $connectionPool = $this->connectionPool;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (
                string $table,
                int $workspaceVersionUid,
            ) use (
                $dataHandlerService,
                $connectionPool,
                $logger
            ): RecordDeletedResult|ErrorResult {
                try {
                    $row = self::loadVersionRow($connectionPool, $table, $workspaceVersionUid);
                    if ($row === null) {
                        return new ErrorResult('Workspace version not found', ['table' => $table, 'uid' => $workspaceVersionUid]);
                    }

                    // 'clearWSID' is supported in TYPO3 v13.4 and v14 (the v14 'discard' alias maps to it).
                    $dataHandlerService->processCommand([
                        $table => [
                            $workspaceVersionUid => [
                                'version' => ['action' => 'clearWSID'],
                            ],
                        ],
                    ]);

                    return new RecordDeletedResult($workspaceVersionUid);
                } catch (\Throwable $e) {
                    $logger->error('workspace discard tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }
            },
            name: 'workspace_discard',
            description: 'Discard a workspace version, dropping unpublished changes. Pass table and workspaceVersionUid.'
                . ' The live record (if any) is unaffected.',
        );
    }

    private function registerStageSetTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $connectionPool = $this->connectionPool;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (
                string $table,
                int $workspaceVersionUid,
                int $stage,
            ) use (
                $dataHandlerService,
                $connectionPool,
                $logger
            ): RecordUpdatedResult|ErrorResult {
                try {
                    $beUser = self::requireBackendUser();
                    // @phpstan-ignore method.internal
                    if (!$beUser->workspaceCheckStageForCurrent($stage)) {
                        return new ErrorResult('Stage not accessible to current user', ['stage' => $stage]);
                    }

                    $row = self::loadVersionRow($connectionPool, $table, $workspaceVersionUid);
                    if ($row === null) {
                        return new ErrorResult('Workspace version not found', ['table' => $table, 'uid' => $workspaceVersionUid]);
                    }

                    $dataHandlerService->updateRecord($table, $workspaceVersionUid, ['t3ver_stage' => $stage]);

                    return new RecordUpdatedResult($workspaceVersionUid, ['t3ver_stage']);
                } catch (\Throwable $e) {
                    $logger->error('workspace stage set tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }
            },
            name: 'workspace_stage_set',
            description: 'Move a workspace version to a different stage. Pass table, workspaceVersionUid, and stage.'
                . ' Built-in stages: 0 = editing, -10 = ready to publish, -20 = ready to review.'
                . ' Custom stage uids (>0) reference sys_workspace_stage. Validates current user access via workspaceCheckStageForCurrent.',
        );
    }

    private static function requireBackendUser(): BackendUserAuthentication
    {
        if (!isset($GLOBALS['BE_USER']) || !$GLOBALS['BE_USER'] instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1712000050);
        }

        return $GLOBALS['BE_USER'];
    }

    /** @return list<string> */
    private static function workspaceAwareTables(): array
    {
        $tables = [];
        /** @var array<string, array{ctrl?: array<string, mixed>}> $tca */
        $tca = $GLOBALS['TCA'] ?? [];
        foreach ($tca as $name => $config) {
            if ((bool) ($config['ctrl']['versioningWS'] ?? false)) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /** @return array<string, mixed>|null */
    private static function loadVersionRow(ConnectionPool $connectionPool, string $table, int $uid): ?array
    {
        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid', 't3ver_state', 't3ver_stage', 't3ver_wsid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    private static function stateLabel(int $state): string
    {
        return match ($state) {
            0 => 'default',
            1 => 'new',
            2 => 'deletePlaceholder',
            4 => 'movePointer',
            default => 'unknown',
        };
    }
}
