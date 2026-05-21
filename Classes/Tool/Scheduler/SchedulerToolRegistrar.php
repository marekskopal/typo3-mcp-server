<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Scheduler;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use const JSON_THROW_ON_ERROR;

readonly class SchedulerToolRegistrar
{
    private const string TABLE = 'tx_scheduler_task';

    private const array CANDIDATE_FIELDS = [
        'uid',
        'pid',
        'tasktype',
        'task_group',
        'description',
        'disable',
        'nextexecution',
        'lastexecution_time',
        'lastexecution_failure',
        'lastexecution_context',
    ];

    private const array CANDIDATE_WRITABLE_FIELDS = [
        'disable',
        'description',
        'task_group',
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
        if (!ExtensionManagementUtility::isLoaded('scheduler')) {
            return;
        }

        $fields = $this->filterExistingColumns(self::TABLE, self::CANDIDATE_FIELDS);
        if ($fields === []) {
            return;
        }

        $writableFields = $this->filterExistingColumns(self::TABLE, self::CANDIDATE_WRITABLE_FIELDS);

        $this->registerListTool($builder, $fields);
        $this->registerGetTool($builder, $fields);
        $this->registerUpdateTool($builder, $writableFields);
        $this->registerDeleteTool($builder);
    }

    /**
     * @param non-empty-string $table
     * @param list<string> $candidates
     * @return list<string>
     */
    private function filterExistingColumns(string $table, array $candidates): array
    {
        try {
            $tableInfo = $this->connectionPool->getConnectionForTable($table)
                ->createSchemaManager()
                ->introspectTableByUnquotedName($table);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to introspect ' . $table . ' columns', ['exception' => $e]);

            return [];
        }

        return array_values(array_filter(
            $candidates,
            static fn (string $field): bool => $tableInfo->hasColumn($field),
        ));
    }

    /** @param list<string> $fields */
    private function registerListTool(Builder $builder, array $fields): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;
        $hasTasktype = in_array('tasktype', $fields, true);
        $hasTaskGroup = in_array('task_group', $fields, true);
        $hasDisable = in_array('disable', $fields, true);

        $builder->addTool(
            handler: static function (
                int $limit = 20,
                int $offset = 0,
                string $tasktype = '',
                int $taskGroup = -1,
                int $disable = -1,
            ) use (
                $recordService,
                $logger,
                $fields,
                $hasTasktype,
                $hasTaskGroup,
                $hasDisable,
            ): string {
                /** @var array<string, array{operator: string, value: string}> $conditions */
                $conditions = [];

                if ($tasktype !== '' && $hasTasktype) {
                    $conditions['tasktype'] = ['operator' => 'like', 'value' => $tasktype];
                }
                if ($taskGroup >= 0 && $hasTaskGroup) {
                    $conditions['task_group'] = ['operator' => 'eq', 'value' => (string) $taskGroup];
                }
                if ($disable >= 0 && $hasDisable) {
                    $conditions['disable'] = ['operator' => 'eq', 'value' => (string) $disable];
                }

                try {
                    $result = $recordService->search(
                        self::TABLE,
                        $conditions,
                        $limit,
                        $offset,
                        $fields,
                        null,
                        'uid',
                        'ASC',
                    );
                } catch (\Throwable $e) {
                    $logger->error('scheduler list tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return json_encode($result, JSON_THROW_ON_ERROR);
            },
            name: 'scheduler_list',
            description: 'List scheduler tasks with pagination and optional filtering.'
                . ' Use tasktype for text search by task class name (LIKE; ignored when the column is unavailable).'
                . ' Use taskGroup to filter by group ID. Use disable (0 or 1) to filter by status.',
        );
    }

    /** @param list<string> $fields */
    private function registerGetTool(Builder $builder, array $fields): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (int $uid) use ($recordService, $logger, $fields): string {
                try {
                    $record = $recordService->findByUid(self::TABLE, $uid, $fields);
                } catch (\Throwable $e) {
                    $logger->error('scheduler get tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                if ($record === null) {
                    return json_encode(['error' => 'Scheduler task not found'], JSON_THROW_ON_ERROR);
                }

                return json_encode($record, JSON_THROW_ON_ERROR);
            },
            name: 'scheduler_get',
            description: 'Get a single scheduler task by its uid.',
        );
    }

    /** @param list<string> $writableFields */
    private function registerUpdateTool(Builder $builder, array $writableFields): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (
                int $uid,
                string $fields,
            ) use (
                $dataHandlerService,
                $logger,
                $writableFields,
            ): RecordUpdatedResult|ErrorResult {
                /** @var array<string, mixed> $data */
                $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

                $filteredData = array_intersect_key($data, array_flip($writableFields));
                $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

                if ($filteredData === []) {
                    return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
                }

                try {
                    $dataHandlerService->updateRecord(self::TABLE, $uid, $filteredData);
                } catch (\Throwable $e) {
                    $logger->error('scheduler update tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new RecordUpdatedResult($uid, array_keys($filteredData), $ignoredFields);
            },
            name: 'scheduler_update',
            description: 'Update a scheduler task. Pass fields as a JSON object string.'
                . ' Available fields: ' . implode(', ', $writableFields) . '.',
        );
    }

    private function registerDeleteTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (int $uid) use ($dataHandlerService, $logger): RecordDeletedResult {
                try {
                    $dataHandlerService->deleteRecord(self::TABLE, $uid);
                } catch (\Throwable $e) {
                    $logger->error('scheduler delete tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new RecordDeletedResult($uid);
            },
            name: 'scheduler_delete',
            description: 'Delete a scheduler task by its uid.',
        );
    }
}
