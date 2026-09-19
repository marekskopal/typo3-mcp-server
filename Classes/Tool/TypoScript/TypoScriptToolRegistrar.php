<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\TypoScript;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\ExplicitFields;
use MarekSkopal\MsMcpServer\Tool\Helper\RegistrarToolRunner;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordListResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolFactory;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

/**
 * CRUD tools for `sys_template`, the TypoScript template records.
 *
 * Unlike the redirect and scheduler groups there is no extension to check for: the `sys_template`
 * TCA ships in `typo3/cms-frontend`, a hard dependency of this extension, not in the optional
 * `typo3/cms-tstemplate` (which contributes only the backend module). The guard is on the TCA
 * itself, so a stripped or overridden schema produces no tools rather than broken ones.
 *
 * `sys_template` carries `ctrl.adminOnly`, which DataHandler enforces on every write and
 * `PermissionService::canSelectTable()` enforces on every read — so these tools need no admin
 * check of their own.
 */
readonly class TypoScriptToolRegistrar
{
    private const string TABLE = 'sys_template';

    private const array LIST_FIELDS = ['uid', 'pid', 'sorting', 'title', 'root', 'clear', 'hidden'];

    private const array READ_FIELDS = [
        'uid',
        'pid',
        'sorting',
        'title',
        'root',
        'clear',
        'constants',
        'config',
        'include_static_file',
        'basedOn',
        'includeStaticAfterBasedOn',
        'static_file_mode',
        'description',
        'hidden',
        'starttime',
        'endtime',
    ];

    private const array WRITABLE_FIELDS = [
        'title',
        'root',
        'clear',
        'constants',
        'config',
        'include_static_file',
        'basedOn',
        'includeStaticAfterBasedOn',
        'static_file_mode',
        'description',
        'hidden',
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
     * `sys_template` described for the shared table tools. Only `typoscript_list` and
     * `typoscript_create` are hand-written: the list filters on template-specific columns, and
     * create takes title and the two source fields as explicit parameters so an agent does not
     * have to discover them through a JSON blob.
     */
    private function config(): TableToolConfig
    {
        return new TableToolConfig(
            tableName: self::TABLE,
            label: 'TypoScript',
            prefix: 'typoscript',
            listFields: self::LIST_FIELDS,
            readFields: self::READ_FIELDS,
            writableFields: self::WRITABLE_FIELDS,
            noun: 'template',
        );
    }

    public function register(Builder $builder): void
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca) || !isset($tca[self::TABLE])) {
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
                string $title = '',
                int $root = -1,
                int $hidden = -1,
            ) use (
                $recordService,
                $logger,
                $auditLogger
            ): RecordListResult {
                return RegistrarToolRunner::run('typoscript_list', $auditLogger, $logger, static function () use (
                    $recordService,
                    $pid,
                    $limit,
                    $offset,
                    $title,
                    $root,
                    $hidden,
                ): RecordListResult {
                    /** @var array<string, array{operator: string, value: string}> $conditions */
                    $conditions = [];

                    if ($title !== '') {
                        $conditions['title'] = ['operator' => 'like', 'value' => $title];
                    }
                    if ($root >= 0) {
                        $conditions['root'] = ['operator' => 'eq', 'value' => (string) $root];
                    }
                    if ($hidden >= 0) {
                        $conditions['hidden'] = ['operator' => 'eq', 'value' => (string) $hidden];
                    }

                    $result = $recordService->search(
                        self::TABLE,
                        $conditions,
                        $limit,
                        $offset,
                        self::LIST_FIELDS,
                        $pid > 0 ? $pid : null,
                        'uid',
                        'ASC',
                    );

                    return RecordListResult::fromQuery($result);
                }, arguments: [$pid, $limit, $offset, $title, $root, $hidden], tableName: self::TABLE);
            },
            name: 'typoscript_list',
            description: 'List TypoScript template (sys_template) records with pagination and optional filtering.'
                . ' Use title for text search (LIKE), root (0 or 1) to find site root templates,'
                . ' hidden (0 or 1) to filter by status, and pid to limit to one page (0 = all pages).'
                . ' The constants and config source is not included here; read it with typoscript_get.',
        );
    }

    private function registerCreateTool(Builder $builder): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;

        $builder->addTool(
            handler: static function (
                int $pid,
                string $title,
                int $root = 1,
                int $clear = 3,
                string $constants = '',
                string $config = '',
                string $fields = '',
            ) use (
                $dataHandlerService,
                $logger,
                $auditLogger
            ): RecordCreatedResult {
                return RegistrarToolRunner::run('typoscript_create', $auditLogger, $logger, static function () use (
                    $dataHandlerService,
                    $pid,
                    $title,
                    $root,
                    $clear,
                    $constants,
                    $config,
                    $fields,
                ): RecordCreatedResult {
                    [$data, $ignoredFields] = ExplicitFields::merge([
                        'title' => $title,
                        'root' => $root,
                        'clear' => $clear,
                        'constants' => $constants,
                        'config' => $config,
                    ], $fields, self::WRITABLE_FIELDS);

                    $uid = $dataHandlerService->createRecord(self::TABLE, $pid, $data);

                    return new RecordCreatedResult($uid, $ignoredFields);
                }, arguments: [$pid, $title, $root, $clear, $constants, $config, $fields], tableName: self::TABLE);
            },
            name: 'typoscript_create',
            description: 'Create a TypoScript template (sys_template) record. Required: pid (the page the template'
                . ' is attached to) and title. Optional: root (1 = this template starts a new TypoScript scope for'
                . ' the page subtree, the default); clear, a bitmask deciding what inherited TypoScript is discarded'
                . ' (0 nothing, 1 constants, 2 setup, 3 both — the default); constants and config, the TypoScript'
                . ' source; and fields as JSON for the rest: include_static_file (comma-separated'
                . ' "EXT:<key>/<path>" identifiers), basedOn (comma-separated sys_template uids),'
                . ' includeStaticAfterBasedOn, static_file_mode, description, hidden, starttime, endtime.',
        );
    }
}
