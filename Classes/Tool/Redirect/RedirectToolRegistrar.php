<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Redirect;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\RegistrarToolRunner;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use const JSON_THROW_ON_ERROR;

readonly class RedirectToolRegistrar
{
    private const string TABLE = 'sys_redirect';

    private const array LIST_FIELDS = [
        'uid',
        'pid',
        'source_host',
        'source_path',
        'target',
        'target_statuscode',
        'disabled',
    ];

    private const array READ_FIELDS = [
        'uid',
        'pid',
        'source_host',
        'source_path',
        'is_regexp',
        'target',
        'target_statuscode',
        'force_https',
        'keep_query_parameters',
        'respect_query_parameters',
        'protected',
        'disabled',
        'description',
        'hitcount',
        'lasthiton',
        'creation_type',
        'starttime',
        'endtime',
    ];

    private const array WRITABLE_FIELDS = [
        'source_host',
        'source_path',
        'is_regexp',
        'target',
        'target_statuscode',
        'force_https',
        'keep_query_parameters',
        'respect_query_parameters',
        'protected',
        'disabled',
        'description',
        'starttime',
        'endtime',
    ];

    public function __construct(
        private RecordService $recordService,
        private DataHandlerService $dataHandlerService,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
    ) {
    }

    public function register(Builder $builder): void
    {
        if (!ExtensionManagementUtility::isLoaded('redirects')) {
            return;
        }

        $this->registerListTool($builder);
        $this->registerGetTool($builder);
        $this->registerCreateTool($builder);
        $this->registerUpdateTool($builder);
        $this->registerDeleteTool($builder);
    }

    private function registerListTool(Builder $builder): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (
                int $pid = 0,
                int $limit = 20,
                int $offset = 0,
                string $sourceHost = '',
                string $sourcePath = '',
                string $target = '',
                int $disabled = -1,
            ) use (
                $recordService,
                $logger,
                $auditLogger
            ): string {
                return RegistrarToolRunner::run('redirect_list', $auditLogger, $logger, static function () use (
                    $recordService,
                    $pid,
                    $limit,
                    $offset,
                    $sourceHost,
                    $sourcePath,
                    $target,
                    $disabled,
                ): string {
                    /** @var array<string, array{operator: string, value: string}> $conditions */
                    $conditions = [];

                    if ($sourceHost !== '') {
                        $conditions['source_host'] = ['operator' => 'like', 'value' => $sourceHost];
                    }
                    if ($sourcePath !== '') {
                        $conditions['source_path'] = ['operator' => 'like', 'value' => $sourcePath];
                    }
                    if ($target !== '') {
                        $conditions['target'] = ['operator' => 'like', 'value' => $target];
                    }
                    if ($disabled >= 0) {
                        $conditions['disabled'] = ['operator' => 'eq', 'value' => (string) $disabled];
                    }

                    $result = $recordService->search(
                        self::TABLE,
                        $conditions,
                        $limit,
                        $offset,
                        self::LIST_FIELDS,
                        $pid > 0 ? $pid : null,
                        'uid',
                        'DESC',
                    );

                    return json_encode($result, JSON_THROW_ON_ERROR);
                }, arguments: [$pid, $limit, $offset, $sourceHost, $sourcePath, $target, $disabled], tableName: self::TABLE);
            },
            name: 'redirect_list',
            description: 'List redirect records with pagination and optional filtering.'
                . ' Use sourceHost, sourcePath, target for text search (LIKE).'
                . ' Use disabled (0 or 1) to filter by status. Use pid to filter by page (0 = all pages).',
        );
    }

    private function registerGetTool(Builder $builder): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (int $uid) use ($recordService, $logger, $auditLogger): string {
                return RegistrarToolRunner::run(
                    'redirect_get',
                    $auditLogger,
                    $logger,
                    static function () use ($recordService, $uid): string {
                        $record = $recordService->findByUid(self::TABLE, $uid, self::READ_FIELDS);

                        if ($record === null) {
                            return json_encode(['error' => 'Redirect record not found'], JSON_THROW_ON_ERROR);
                        }

                        return json_encode($record, JSON_THROW_ON_ERROR);
                    },
                    arguments: [$uid],
                    tableName: self::TABLE,
                    recordUid: $uid,
                );
            },
            name: 'redirect_get',
            description: 'Get a single redirect record by its uid.',
        );
    }

    private function registerCreateTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (
                string $sourceHost,
                string $sourcePath,
                string $target,
                int $pid = 0,
                int $targetStatuscode = 301,
                string $fields = '',
            ) use (
                $dataHandlerService,
                $logger,
                $auditLogger
            ): RecordCreatedResult {
                return RegistrarToolRunner::run('redirect_create', $auditLogger, $logger, static function () use (
                    $dataHandlerService,
                    $sourceHost,
                    $sourcePath,
                    $target,
                    $pid,
                    $targetStatuscode,
                    $fields,
                ): RecordCreatedResult {
                    $required = [
                        'source_host' => $sourceHost,
                        'source_path' => $sourcePath,
                        'target' => $target,
                        'target_statuscode' => $targetStatuscode,
                    ];

                    if ($fields !== '') {
                        /** @var array<string, mixed> $extra */
                        $extra = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);
                        // Explicit params take precedence over fields JSON
                        $merged = array_merge($extra, $required);
                    } else {
                        $merged = $required;
                    }

                    $filteredData = array_intersect_key($merged, array_flip(self::WRITABLE_FIELDS));
                    $ignoredFields = array_map(
                        'strval',
                        array_values(array_diff(array_keys($merged), array_keys($filteredData))),
                    );

                    $uid = $dataHandlerService->createRecord(self::TABLE, $pid, $filteredData);

                    return new RecordCreatedResult($uid, $ignoredFields);
                }, arguments: [$sourceHost, $sourcePath, $target, $pid, $targetStatuscode, $fields], tableName: self::TABLE);
            },
            name: 'redirect_create',
            description: 'Create a new redirect record. Required: sourceHost (domain or "*"), sourcePath, target (URL or t3:// link).'
                . ' Optional: pid (default 0), targetStatuscode (default 301),'
                . ' fields as JSON for additional options: is_regexp, force_https, keep_query_parameters,'
                . ' respect_query_parameters, protected, disabled, description, starttime, endtime.',
        );
    }

    private function registerUpdateTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (int $uid, string $fields) use ($dataHandlerService, $logger, $auditLogger): RecordUpdatedResult|ErrorResult {
                return RegistrarToolRunner::run(
                    'redirect_update',
                    $auditLogger,
                    $logger,
                    static function () use ($dataHandlerService, $uid, $fields): RecordUpdatedResult|ErrorResult {
                        /** @var array<string, mixed> $data */
                        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

                        $filteredData = array_intersect_key($data, array_flip(self::WRITABLE_FIELDS));
                        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

                        if ($filteredData === []) {
                            return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
                        }

                        $dataHandlerService->updateRecord(self::TABLE, $uid, $filteredData);

                        return new RecordUpdatedResult($uid, array_keys($filteredData), $ignoredFields);
                    },
                    arguments: [$uid, $fields],
                    tableName: self::TABLE,
                    recordUid: $uid,
                );
            },
            name: 'redirect_update',
            description: 'Update an existing redirect record. Pass fields as a JSON object string.'
                . ' Available fields: ' . implode(', ', self::WRITABLE_FIELDS) . '.',
        );
    }

    private function registerDeleteTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (int $uid) use ($dataHandlerService, $logger, $auditLogger): RecordDeletedResult {
                return RegistrarToolRunner::run(
                    'redirect_delete',
                    $auditLogger,
                    $logger,
                    static function () use ($dataHandlerService, $uid): RecordDeletedResult {
                        $dataHandlerService->deleteRecord(self::TABLE, $uid);

                        return new RecordDeletedResult($uid);
                    },
                    arguments: [$uid],
                    tableName: self::TABLE,
                    recordUid: $uid,
                );
            },
            name: 'redirect_delete',
            description: 'Delete a redirect record by its uid.',
        );
    }
}
