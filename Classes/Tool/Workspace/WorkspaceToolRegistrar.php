<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Workspace;

use Doctrine\DBAL\ParameterType;
use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\RegistrarToolRunner;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
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
        'adminusers',
        'members',
        'db_mountpoints',
        'file_mountpoints',
        'live_edit',
        'custom_stages',
        'publish_access',
    ];

    public function __construct(
        private RecordService $recordService,
        private DataHandlerService $dataHandlerService,
        private ConnectionPool $connectionPool,
        private PermissionService $permissionService,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
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
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function () use ($recordService, $logger, $auditLogger): string {
                return RegistrarToolRunner::run('workspace_list', $auditLogger, $logger, static function () use ($recordService): string {
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
                }, tableName: self::WORKSPACE_TABLE);
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
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (int $workspaceId) use ($recordService, $logger, $auditLogger): string {
                return RegistrarToolRunner::run(
                    'workspace_get',
                    $auditLogger,
                    $logger,
                    static function () use ($recordService, $workspaceId): string {
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
                    },
                    arguments: [$workspaceId],
                    tableName: self::WORKSPACE_TABLE,
                    recordUid: $workspaceId,
                );
            },
            name: 'workspace_get',
            description: 'Get workspace metadata by uid. Returns title, custom_stages flag, and current user access level.'
                . ' Use uid 0 for the live workspace.',
        );
    }

    private function registerSwitchTool(Builder $builder): void
    {
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (int $workspaceId) use ($logger, $auditLogger): RecordUpdatedResult|ErrorResult {
                return RegistrarToolRunner::run(
                    'workspace_switch',
                    $auditLogger,
                    $logger,
                    static function () use ($workspaceId): RecordUpdatedResult|ErrorResult {
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
                    },
                    arguments: [$workspaceId],
                    tableName: self::WORKSPACE_TABLE,
                    recordUid: $workspaceId,
                );
            },
            name: 'workspace_switch',
            description: 'Switch the active workspace for the current backend user. Persists to be_users.workspace_id.'
                . ' Subsequent record reads/writes will use the new workspace context. Use 0 for the live workspace.',
        );
    }

    private function registerChangesListTool(Builder $builder): void
    {
        $connectionPool = $this->connectionPool;
        $permissionService = $this->permissionService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (
                string $table = '',
                int $limit = 100,
            ) use (
                $connectionPool,
                $permissionService,
                $logger,
                $auditLogger
            ): string {
                return RegistrarToolRunner::run(
                    'workspace_changes_list',
                    $auditLogger,
                    $logger,
                    static function () use ($connectionPool, $permissionService, $table, $limit): string {
                        $beUser = self::requireBackendUser();
                        $workspaceId = (int) $beUser->workspace;

                        if ($workspaceId === 0) {
                            return json_encode(['workspaceId' => 0, 'tables' => []], JSON_THROW_ON_ERROR);
                        }

                        // Without the grant check this listed uid, pid and stage of every
                        // workspace-aware table, including those the caller cannot read.
                        $tables = array_values(array_filter(
                            self::workspaceAwareTables(),
                            static fn (string $tableName): bool => $permissionService->canSelectTable($tableName),
                        ));
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
                    },
                    arguments: [$table, $limit],
                );
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
        $permissionService = $this->permissionService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (
                string $table,
                int $workspaceVersionUid,
            ) use (
                $dataHandlerService,
                $connectionPool,
                $permissionService,
                $logger,
                $auditLogger
            ): RecordUpdatedResult|ErrorResult {
                return RegistrarToolRunner::run('workspace_publish', $auditLogger, $logger, static function () use (
                    $dataHandlerService,
                    $connectionPool,
                    $permissionService,
                    $table,
                    $workspaceVersionUid,
                ): RecordUpdatedResult|ErrorResult {
                    $workspaceId = (int) self::requireBackendUser()->workspace;
                    $row = self::loadVersionRow($connectionPool, $permissionService, $table, $workspaceVersionUid, $workspaceId);
                    if ($row === null) {
                        return self::versionNotFound($table, $workspaceVersionUid, $workspaceId);
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
                }, arguments: [$table, $workspaceVersionUid], tableName: $table, recordUid: $workspaceVersionUid);
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
        $permissionService = $this->permissionService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (
                string $table,
                int $workspaceVersionUid,
            ) use (
                $dataHandlerService,
                $connectionPool,
                $permissionService,
                $logger,
                $auditLogger
            ): RecordDeletedResult|ErrorResult {
                return RegistrarToolRunner::run('workspace_discard', $auditLogger, $logger, static function () use (
                    $dataHandlerService,
                    $connectionPool,
                    $permissionService,
                    $table,
                    $workspaceVersionUid,
                ): RecordDeletedResult|ErrorResult {
                    $workspaceId = (int) self::requireBackendUser()->workspace;
                    $row = self::loadVersionRow($connectionPool, $permissionService, $table, $workspaceVersionUid, $workspaceId);
                    if ($row === null) {
                        return self::versionNotFound($table, $workspaceVersionUid, $workspaceId);
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
                }, arguments: [$table, $workspaceVersionUid], tableName: $table, recordUid: $workspaceVersionUid);
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
        $permissionService = $this->permissionService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (
                string $table,
                int $workspaceVersionUid,
                int $stage,
            ) use (
                $dataHandlerService,
                $connectionPool,
                $permissionService,
                $logger,
                $auditLogger
            ): RecordUpdatedResult|ErrorResult {
                return RegistrarToolRunner::run('workspace_stage_set', $auditLogger, $logger, static function () use (
                    $dataHandlerService,
                    $connectionPool,
                    $permissionService,
                    $table,
                    $workspaceVersionUid,
                    $stage,
                ): RecordUpdatedResult|ErrorResult {
                    $beUser = self::requireBackendUser();
                    // @phpstan-ignore method.internal
                    if (!$beUser->workspaceCheckStageForCurrent($stage)) {
                        return new ErrorResult('Stage not accessible to current user', ['stage' => $stage]);
                    }

                    $workspaceId = (int) self::requireBackendUser()->workspace;
                    $row = self::loadVersionRow($connectionPool, $permissionService, $table, $workspaceVersionUid, $workspaceId);
                    if ($row === null) {
                        return self::versionNotFound($table, $workspaceVersionUid, $workspaceId);
                    }

                    $dataHandlerService->updateRecord($table, $workspaceVersionUid, ['t3ver_stage' => $stage]);

                    return new RecordUpdatedResult($workspaceVersionUid, ['t3ver_stage']);
                }, arguments: [$table, $workspaceVersionUid, $stage], tableName: $table, recordUid: $workspaceVersionUid);
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

    /**
     * The workspace version with $uid, or null when it does not exist, belongs to another
     * workspace, lives in a table the caller may not read, or in one that is not workspace-aware.
     *
     * Every refusal returns the same null and the callers report one message, so these tools
     * cannot be used to probe which uids exist in a table outside the caller's `tables_select`
     * grant — the table name is a free-form parameter, and the query ran with all restrictions
     * removed and no permission check at all.
     *
     * @return array<string, mixed>|null
     */
    private static function loadVersionRow(
        ConnectionPool $connectionPool,
        PermissionService $permissionService,
        string $table,
        int $uid,
        int $workspaceId,
    ): ?array {
        // A table without t3ver_* columns would otherwise reach the query and fail as an opaque
        // internal error; one outside the grant would answer "found" for a uid the caller may not
        // see. Neither says anything the caller is entitled to.
        if (!in_array($table, self::workspaceAwareTables(), true) || !$permissionService->canSelectTable($table)) {
            return null;
        }

        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'pid', 't3ver_oid', 't3ver_state', 't3ver_stage', 't3ver_wsid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                // A version belongs to exactly one workspace. Acting on one from a different
                // workspace than the caller's is not a flow the backend offers — the workspace
                // module always works inside the selected workspace — and permitting it here would
                // let a member of one workspace publish or discard another's work.
                $queryBuilder->expr()->eq(
                    't3ver_wsid',
                    $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * One message for every reason a version could not be loaded, so the tools stay silent about
     * which of them applied. It names `workspace_switch` because "not found" is most often the
     * caller standing in the wrong workspace, and an agent has to be able to correct itself.
     */
    private static function versionNotFound(string $table, int $uid, int $workspaceId): ErrorResult
    {
        return new ErrorResult(
            'Workspace version not found in workspace ' . $workspaceId
                . '. Check the uid against workspace_changes_list, and use workspace_switch if the version belongs'
                . ' to another workspace.',
            ['table' => $table, 'uid' => $uid, 'workspaceId' => $workspaceId],
        );
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
