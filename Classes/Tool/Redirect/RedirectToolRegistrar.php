<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Redirect;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use MarekSkopal\MsMcpServer\Tool\Helper\RegistrarToolRunner;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolFactory;
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
        private TableToolFactory $tableToolFactory,
    ) {
    }

    /**
     * `sys_redirect` described for the shared table tools. Only `redirect_list` and
     * `redirect_create` are hand-written below: the list filters on redirect-specific columns and
     * create takes the three required parts as explicit parameters, neither of which the generic
     * tools model. Get, update and delete were near-copies of the dynamic ones and are now the
     * same code.
     */
    private function config(): TableToolConfig
    {
        return new TableToolConfig(
            tableName: self::TABLE,
            label: 'redirect',
            prefix: 'redirect',
            listFields: self::LIST_FIELDS,
            readFields: self::READ_FIELDS,
            writableFields: self::WRITABLE_FIELDS,
        );
    }

    public function register(Builder $builder): void
    {
        if (!ExtensionManagementUtility::isLoaded('redirects')) {
            return;
        }

        $config = $this->config();

        $this->registerListTool($builder);
        $this->tableToolFactory->register($builder, $this->tableToolFactory->get($config));
        $this->registerCreateTool($builder);
        $this->tableToolFactory->register($builder, $this->tableToolFactory->update($config));
        $this->tableToolFactory->register($builder, $this->tableToolFactory->delete($config));
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
                        $extra = JsonObjectParser::parse($fields, 'fields');
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
}
