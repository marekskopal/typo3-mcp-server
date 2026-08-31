<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Scheduler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\RegistrarToolRunner;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolFactory;
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
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
        private TableToolFactory $tableToolFactory,
    ) {
    }

    /**
     * `tx_scheduler_task` described for the shared table tools. Field lists are introspected rather
     * than fixed, because which columns exist varies across TYPO3 versions. `noun: 'task'` keeps the
     * tool text reading "scheduler task" rather than "scheduler task record"; the not-found message
     * capitalises it back to "Scheduler task not found".
     *
     * Only `scheduler_list` stays hand-written: it filters on task type, group and disabled state.
     *
     * @param list<string> $fields
     * @param list<string> $writableFields
     */
    private function config(array $fields, array $writableFields): TableToolConfig
    {
        return new TableToolConfig(
            tableName: self::TABLE,
            label: 'scheduler',
            prefix: 'scheduler',
            listFields: $fields,
            readFields: $fields,
            writableFields: $writableFields,
            noun: 'task',
        );
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

        $config = $this->config($fields, $writableFields);

        $this->registerListTool($builder, $fields);
        $this->tableToolFactory->register($builder, $this->tableToolFactory->get($config));
        $this->tableToolFactory->register($builder, $this->tableToolFactory->update($config));
        $this->tableToolFactory->register($builder, $this->tableToolFactory->delete($config));
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
        $auditLogger = $this->auditLogger;
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
                $auditLogger,
                $fields,
                $hasTasktype,
                $hasTaskGroup,
                $hasDisable,
            ): string {
                return RegistrarToolRunner::run('scheduler_list', $auditLogger, $logger, static function () use (
                    $recordService,
                    $fields,
                    $hasTasktype,
                    $hasTaskGroup,
                    $hasDisable,
                    $limit,
                    $offset,
                    $tasktype,
                    $taskGroup,
                    $disable,
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

                    return json_encode($result, JSON_THROW_ON_ERROR);
                }, arguments: [$limit, $offset, $tasktype, $taskGroup, $disable], tableName: self::TABLE);
            },
            name: 'scheduler_list',
            description: 'List scheduler tasks with pagination and optional filtering.'
                . ' Use tasktype for text search by task class name (LIKE; ignored when the column is unavailable).'
                . ' Use taskGroup to filter by group ID. Use disable (0 or 1) to filter by status.',
        );
    }
}
