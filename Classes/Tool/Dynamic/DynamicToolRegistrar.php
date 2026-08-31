<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Dynamic;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Repository\DiscoveredTableRepository;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use MarekSkopal\MsMcpServer\Tool\Helper\MoveTarget;
use MarekSkopal\MsMcpServer\Tool\Helper\RegistrarToolRunner;
use MarekSkopal\MsMcpServer\Tool\Helper\UidListParser;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsMovedResult;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsUpdatedResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordMovedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use Mcp\Exception\ToolCallException;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class DynamicToolRegistrar
{
    /** Table-name prefixes that discovery never exposes; re-checked here in case a row was tampered with. */
    private const array EXCLUDED_TABLE_PREFIXES = ['sys_', 'be_', 'fe_', 'cache_', 'cf_', 'index_', 'tx_msmcpserver_'];

    private const array EXCLUDED_TABLES = ['pages', 'tt_content'];

    public function __construct(
        private RecordService $recordService,
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private DiscoveredTableRepository $discoveredTableRepository,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
    ) {
    }

    public function register(Builder $builder): void
    {
        /** @var array<string, array{label: string, prefix: string, listFields?: list<string>, readFields?: list<string>, writableFields?: list<string>}> $tables */
        $tables = $this->getTablesConfiguration();

        foreach ($tables as $tableName => $config) {
            $resolvedConfig = $this->resolveConfig($tableName, $config);

            if ($resolvedConfig['readFields'] === []) {
                continue;
            }

            $this->registerListTool($builder, $tableName, $resolvedConfig);
            $this->registerGetTool($builder, $tableName, $resolvedConfig);
            $this->registerCreateTool($builder, $tableName, $resolvedConfig);
            $this->registerUpdateTool($builder, $tableName, $resolvedConfig);
            $this->registerDeleteTool($builder, $tableName, $resolvedConfig);
            $this->registerMoveTool($builder, $tableName, $resolvedConfig);
            $this->registerDeleteBatchTool($builder, $tableName, $resolvedConfig);
            $this->registerUpdateBatchTool($builder, $tableName, $resolvedConfig);
            $this->registerMoveBatchTool($builder, $tableName, $resolvedConfig);
        }
    }

    /**
     * @param array{label: string, prefix: string, listFields?: list<string>, readFields?: list<string>, writableFields?: list<string>} $config
     * @return array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}}
     */
    private function resolveConfig(string $tableName, array $config): array
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig($tableName);
        $listFields = $config['listFields'] ?? $this->tcaSchemaService->getListFields($tableName);
        $readFields = $config['readFields'] ?? $this->tcaSchemaService->getReadFields($tableName);

        // Ensure language fields are included in list/read fields for translation-aware tables
        if ($translationConfig['languageField'] !== null) {
            if (!in_array($translationConfig['languageField'], $listFields, true)) {
                $listFields[] = $translationConfig['languageField'];
            }
            if (!in_array($translationConfig['languageField'], $readFields, true)) {
                $readFields[] = $translationConfig['languageField'];
            }
        }
        if ($translationConfig['transOrigPointerField'] !== null) {
            if (!in_array($translationConfig['transOrigPointerField'], $listFields, true)) {
                $listFields[] = $translationConfig['transOrigPointerField'];
            }
            if (!in_array($translationConfig['transOrigPointerField'], $readFields, true)) {
                $readFields[] = $translationConfig['transOrigPointerField'];
            }
        }

        return [
            'label' => $config['label'],
            'prefix' => $config['prefix'],
            'listFields' => $listFields,
            'readFields' => $readFields,
            'writableFields' => $config['writableFields'] ?? $this->tcaSchemaService->getWritableFields($tableName),
            'translationConfig' => $translationConfig,
        ];
    }

    /** @return array<mixed> */
    private function getTablesConfiguration(): array
    {
        $extconfTables = $this->getExtconfTables();
        $discoveredTables = $this->getDiscoveredTables();

        // Merge: EXTCONF takes precedence on key collision
        return array_merge($discoveredTables, $extconfTables);
    }

    /** @return array<mixed> */
    private function getExtconfTables(): array
    {
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3ConfVars)) {
            return [];
        }

        $extConf = $typo3ConfVars['EXTCONF'] ?? [];
        if (!is_array($extConf)) {
            return [];
        }

        $msMcpServer = $extConf['ms_mcp_server'] ?? [];
        if (!is_array($msMcpServer)) {
            return [];
        }

        $tables = $msMcpServer['tables'] ?? [];

        return is_array($tables) ? $tables : [];
    }

    /** @return array<string, array{label: string, prefix: string}> */
    private function getDiscoveredTables(): array
    {
        try {
            $rows = $this->discoveredTableRepository->findEnabled();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load discovered tables', ['exception' => $e]);

            return [];
        }

        $tables = [];
        $usedPrefixes = [];
        foreach ($rows as $row) {
            $tableName = $row['table_name'];
            $prefix = $row['prefix'];

            // Discovered-table rows come from the DB and could have been tampered with. Validate the
            // table name and prefix before turning them into tool names/descriptions, so a bad row
            // cannot register tools for a system table, shadow a built-in tool, or inject text.
            if (!$this->isRegisterableTable($tableName) || !$this->isValidPrefix($prefix) || isset($usedPrefixes[$prefix])) {
                $this->logger->warning(
                    'Skipping discovered table with invalid or conflicting configuration',
                    ['table' => $tableName, 'prefix' => $prefix],
                );

                continue;
            }

            $usedPrefixes[$prefix] = true;
            $tables[$tableName] = [
                'label' => $this->sanitizeLabel($row['label']),
                'prefix' => $prefix,
            ];
        }

        return $tables;
    }

    private function isRegisterableTable(string $tableName): bool
    {
        if (in_array($tableName, self::EXCLUDED_TABLES, true)) {
            return false;
        }

        foreach (self::EXCLUDED_TABLE_PREFIXES as $prefix) {
            if (str_starts_with($tableName, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function isValidPrefix(string $prefix): bool
    {
        return ToolPrefixValidator::isValid($prefix);
    }

    private function sanitizeLabel(string $label): string
    {
        // Strip control characters (which could inject newlines/escapes into tool descriptions) and
        // cap the length so a crafted label cannot bloat the tool metadata.
        $sanitized = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $label);

        return mb_substr(trim($sanitized), 0, 128);
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerListTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_list';
        $defaultFields = $config['listFields'];
        $readFields = $config['readFields'];
        $languageField = $config['translationConfig']['languageField'];

        /**
         * @param list<string> $defaultFields
         * @param list<string> $readFields
         * @return list<string>
         */
        $resolveFields = static function (string $selectFieldsRaw, array $defaultFields, array $readFields): array {
            if ($selectFieldsRaw === '') {
                return $defaultFields;
            }

            $requested = array_map('trim', explode(',', $selectFieldsRaw));
            /** @var list<string> $allowed */
            $allowed = array_merge(['uid', 'pid'], $readFields);
            $valid = array_values(array_intersect($requested, $allowed));

            return $valid !== [] ? array_values(array_unique(array_merge(['uid', 'pid'], $valid))) : $defaultFields;
        };

        if ($languageField !== null) {
            $builder->addTool(
                handler: static function (
                    int $pid = 0,
                    int $limit = 20,
                    int $offset = 0,
                    int $sysLanguageUid = -1,
                    string $selectFields = '',
                ) use (
                    $recordService,
                    $logger,
                    $auditLogger,
                    $toolName,
                    $tableName,
                    $defaultFields,
                    $readFields,
                    $languageField,
                    $resolveFields,
                ): string {
                    return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                        $recordService,
                        $tableName,
                        $pid,
                        $limit,
                        $offset,
                        $sysLanguageUid,
                        $defaultFields,
                        $readFields,
                        $languageField,
                        $resolveFields,
                        $selectFields,
                    ): string {
                        /** @var list<string> $fields */
                        $fields = $resolveFields($selectFields, $defaultFields, $readFields);

                        if (!in_array($languageField, $fields, true)) {
                            $fields[] = $languageField;
                        }

                        $result = $recordService->findByPid(
                            $tableName,
                            $pid,
                            $limit,
                            $offset,
                            $fields,
                            $sysLanguageUid >= 0 ? $sysLanguageUid : null,
                            $sysLanguageUid >= 0 ? $languageField : null,
                        );

                        return json_encode($result, JSON_THROW_ON_ERROR);
                    }, arguments: [$pid, $limit, $offset, $sysLanguageUid, $selectFields], tableName: $tableName);
                },
                name: $toolName,
                description: 'List ' . $config['label'] . ' records by parent page ID with pagination.'
                    . ' Use sysLanguageUid to filter by language (0 = default, -1 = all).'
                    . ' Use selectFields (comma-separated) to choose which fields to return.',
            );
        } else {
            $builder->addTool(
                handler: static function (
                    int $pid = 0,
                    int $limit = 20,
                    int $offset = 0,
                    string $selectFields = '',
                ) use (
                    $recordService,
                    $logger,
                    $auditLogger,
                    $toolName,
                    $tableName,
                    $defaultFields,
                    $readFields,
                    $resolveFields,
                ): string {
                    return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                        $recordService,
                        $tableName,
                        $pid,
                        $limit,
                        $offset,
                        $defaultFields,
                        $readFields,
                        $resolveFields,
                        $selectFields,
                    ): string {
                        /** @var list<string> $fields */
                        $fields = $resolveFields($selectFields, $defaultFields, $readFields);

                        $result = $recordService->findByPid($tableName, $pid, $limit, $offset, $fields);

                        return json_encode($result, JSON_THROW_ON_ERROR);
                    }, arguments: [$pid, $limit, $offset, $selectFields], tableName: $tableName);
                },
                name: $toolName,
                description: 'List ' . $config['label'] . ' records by parent page ID with pagination.'
                    . ' Use selectFields (comma-separated) to choose which fields to return.',
            );
        }
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerGetTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_get';
        $fields = $config['readFields'];
        $label = $config['label'];
        $languageField = $config['translationConfig']['languageField'];
        $transOrigPointerField = $config['translationConfig']['transOrigPointerField'];

        $builder->addTool(
            handler: static function (int $uid) use ($recordService, $logger, $auditLogger, $toolName, $tableName, $fields, $label, $languageField, $transOrigPointerField): string {
                return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                    $recordService,
                    $tableName,
                    $uid,
                    $fields,
                    $label,
                    $languageField,
                    $transOrigPointerField,
                ): string {
                    $record = $recordService->findByUid($tableName, $uid, $fields);

                    if ($record === null) {
                        return json_encode(['error' => $label . ' record not found'], JSON_THROW_ON_ERROR);
                    }

                    $langValue = $record[$languageField ?? ''] ?? -1;
                    if (
                        $languageField !== null
                        && $transOrigPointerField !== null
                        && (
                            is_int($langValue)
                            || is_string(
                                $langValue,
                            )
                        )
                        && (int) $langValue === 0
                    ) {
                        $record['translations'] = $recordService->findTranslations(
                            $tableName,
                            $uid,
                            $languageField,
                            $transOrigPointerField,
                        );
                    }

                    return json_encode($record, JSON_THROW_ON_ERROR);
                }, arguments: [$uid], tableName: $tableName, recordUid: $uid);
            },
            name: $toolName,
            description: 'Get a single ' . $config['label'] . ' record by its uid.',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerCreateTool(Builder $builder, string $tableName, array $config): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_create';
        $writableFields = $config['writableFields'];
        $languageField = $config['translationConfig']['languageField'];

        // Takes the raw JSON string, not a decoded array: decoding outside RegistrarToolRunner
        // let a JsonException escape past it, so a malformed `fields` produced no audit entry and
        // no error sanitisation. Every sibling (*_update, *_update_batch, redirect_update,
        // scheduler_update) already decodes inside the wrapped closure.
        $createHandler = static function (
            string $fields,
            int $pid,
            int $sysLanguageUid,
        ) use (
            $dataHandlerService,
            $logger,
            $auditLogger,
            $toolName,
            $tableName,
            $writableFields,
            $languageField,
        ): RecordCreatedResult|ErrorResult {
            return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                $dataHandlerService,
                $tableName,
                $writableFields,
                $languageField,
                $fields,
                $pid,
                $sysLanguageUid,
            ): RecordCreatedResult|ErrorResult {
                $data = JsonObjectParser::parse($fields, 'fields');

                $filteredData = array_intersect_key($data, array_flip($writableFields));

                if ($languageField !== null) {
                    $filteredData[$languageField] = $sysLanguageUid;
                    unset($data[$languageField]);
                }

                $ignoredFields = array_map('strval', array_values(array_diff(array_keys($data), array_keys($filteredData))));

                if ($filteredData === []) {
                    return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
                }

                $uid = $dataHandlerService->createRecord($tableName, $pid, $filteredData);

                return new RecordCreatedResult($uid, $ignoredFields);
            }, arguments: [$fields, $pid, $sysLanguageUid], tableName: $tableName);
        };

        $description = 'Create a new ' . $config['label'] . ' record. Pass fields as a JSON object string.'
            . ' Available fields: ' . implode(', ', $config['writableFields']) . '.';

        if ($languageField !== null) {
            $builder->addTool(
                handler: static function (
                    int $pid,
                    string $fields,
                    int $sysLanguageUid = 0,
                ) use ($createHandler): RecordCreatedResult|ErrorResult {
                    return $createHandler($fields, $pid, $sysLanguageUid);
                },
                name: $config['prefix'] . '_create',
                description: $description
                    . ' Use sysLanguageUid to set the language (0 = default, -1 = all languages).',
            );
        } else {
            $builder->addTool(
                handler: static function (
                    int $pid,
                    string $fields,
                ) use ($createHandler): RecordCreatedResult|ErrorResult {
                    return $createHandler($fields, $pid, 0);
                },
                name: $config['prefix'] . '_create',
                description: $description,
            );
        }
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerUpdateTool(Builder $builder, string $tableName, array $config): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_update';
        $writableFields = $config['writableFields'];

        $builder->addTool(
            handler: static function (
                int $uid,
                string $fields,
            ) use (
                $dataHandlerService,
                $logger,
                $auditLogger,
                $toolName,
                $tableName,
                $writableFields,
            ): RecordUpdatedResult|ErrorResult {
                return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                    $dataHandlerService,
                    $tableName,
                    $writableFields,
                    $uid,
                    $fields,
                ): RecordUpdatedResult|ErrorResult {
                    $data = JsonObjectParser::parse($fields, 'fields');

                    $filteredData = array_intersect_key($data, array_flip($writableFields));
                    $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

                    if ($filteredData === []) {
                        return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
                    }

                    $dataHandlerService->updateRecord($tableName, $uid, $filteredData);

                    return new RecordUpdatedResult($uid, array_keys($filteredData), $ignoredFields);
                }, arguments: [$uid, $fields], tableName: $tableName, recordUid: $uid);
            },
            name: $toolName,
            description: 'Update an existing ' . $config['label'] . ' record. Pass fields as a JSON object string'
                . ' with field names and their new values. Available fields: '
                . implode(', ', $config['writableFields']) . '.',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerDeleteTool(Builder $builder, string $tableName, array $config): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_delete';

        $builder->addTool(
            handler: static function (int $uid) use ($dataHandlerService, $logger, $auditLogger, $toolName, $tableName): RecordDeletedResult {
                return RegistrarToolRunner::run(
                    $toolName,
                    $auditLogger,
                    $logger,
                    static function () use ($dataHandlerService, $tableName, $uid): RecordDeletedResult {
                        $dataHandlerService->deleteRecord($tableName, $uid);

                        return new RecordDeletedResult($uid);
                    },
                    arguments: [$uid],
                    tableName: $tableName,
                    recordUid: $uid,
                );
            },
            name: $toolName,
            description: 'Delete a ' . $config['label'] . ' record by its uid.',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerMoveTool(Builder $builder, string $tableName, array $config): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_move';

        $builder->addTool(
            handler: static function (
                int $uid,
                int $targetPid = -1,
                int $afterUid = 0,
            ) use (
                $dataHandlerService,
                $logger,
                $auditLogger,
                $toolName,
                $tableName
            ): RecordMovedResult|ErrorResult {
                return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                    $dataHandlerService,
                    $tableName,
                    $uid,
                    $targetPid,
                    $afterUid,
                ): RecordMovedResult|ErrorResult {
                    $target = MoveTarget::resolve($targetPid, $afterUid);
                    if ($target instanceof ErrorResult) {
                        return $target;
                    }

                    $dataHandlerService->moveRecord($tableName, $uid, $target);

                    return new RecordMovedResult($uid, $target);
                }, arguments: [$uid, $targetPid, $afterUid], tableName: $tableName, recordUid: $uid);
            },
            name: $toolName,
            description: 'Move a ' . $config['label'] . ' record to a new position. Provide exactly one of:'
                . ' targetPid (move to the top of that page) or afterUid (place after that sibling record).',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerDeleteBatchTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_delete_batch';

        $builder->addTool(
            handler: static function (string $uids) use ($recordService, $dataHandlerService, $logger, $auditLogger, $toolName, $tableName): BatchRecordsDeletedResult {
                return RegistrarToolRunner::run(
                    $toolName,
                    $auditLogger,
                    $logger,
                    static function () use ($recordService, $dataHandlerService, $tableName, $uids): BatchRecordsDeletedResult {
                        $uidList = UidListParser::parse($uids);
                        $existingUids = $recordService->findExistingUids($tableName, $uidList);

                        if ($existingUids === []) {
                            throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
                        }

                        $skippedUids = array_values(array_diff($uidList, $existingUids));

                        $dataHandlerService->deleteRecords($tableName, $existingUids);

                        return new BatchRecordsDeletedResult($existingUids, count($existingUids), $skippedUids);
                    },
                    arguments: [$uids],
                    tableName: $tableName,
                );
            },
            name: $toolName,
            description: 'Delete multiple ' . $config['label'] . ' records in a single operation.'
                . ' Pass UIDs as a comma-separated string (e.g. "1,2,3").'
                . ' Non-existent UIDs are skipped and reported in skippedUids.',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerUpdateBatchTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_update_batch';
        $writableFields = $config['writableFields'];

        $builder->addTool(
            handler: static function (
                string $uids,
                string $fields,
            ) use (
                $recordService,
                $dataHandlerService,
                $logger,
                $auditLogger,
                $toolName,
                $tableName,
                $writableFields,
            ): BatchRecordsUpdatedResult {
                return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                    $recordService,
                    $dataHandlerService,
                    $tableName,
                    $writableFields,
                    $uids,
                    $fields,
                ): BatchRecordsUpdatedResult {
                    $uidList = UidListParser::parse($uids);
                    $existingUids = $recordService->findExistingUids($tableName, $uidList);

                    if ($existingUids === []) {
                        throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
                    }

                    $skippedUids = array_values(array_diff($uidList, $existingUids));

                    $fieldData = JsonObjectParser::parse($fields, 'fields');

                    $validFields = [];
                    $ignoredFields = [];
                    foreach ($fieldData as $field => $value) {
                        if (in_array($field, $writableFields, true)) {
                            $validFields[$field] = $value;
                        } else {
                            $ignoredFields[] = $field;
                        }
                    }

                    if ($validFields === []) {
                        throw new ToolCallException('No valid writable fields provided');
                    }

                    $dataHandlerService->updateRecords($tableName, $existingUids, $validFields);

                    return new BatchRecordsUpdatedResult(
                        $existingUids,
                        count($existingUids),
                        array_keys($validFields),
                        $ignoredFields,
                        $skippedUids,
                    );
                }, arguments: [$uids, $fields], tableName: $tableName);
            },
            name: $toolName,
            description: 'Update the same fields on multiple ' . $config['label'] . ' records.'
                . ' Pass UIDs as comma-separated (e.g. "1,2,3") and fields as a JSON object (e.g. {"hidden":1}).'
                . ' Available fields: ' . implode(', ', $config['writableFields']) . '.'
                . ' Non-existent UIDs are skipped and reported in skippedUids.',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerMoveBatchTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $auditLogger = $this->auditLogger;
        $toolName = $config['prefix'] . '_move_batch';

        $builder->addTool(
            handler: static function (
                string $uids,
                int $targetPid = -1,
                int $afterUid = 0,
            ) use (
                $recordService,
                $dataHandlerService,
                $logger,
                $auditLogger,
                $toolName,
                $tableName
            ): BatchRecordsMovedResult|ErrorResult {
                return RegistrarToolRunner::run($toolName, $auditLogger, $logger, static function () use (
                    $recordService,
                    $dataHandlerService,
                    $tableName,
                    $uids,
                    $targetPid,
                    $afterUid,
                ): BatchRecordsMovedResult|ErrorResult {
                    $target = MoveTarget::resolve($targetPid, $afterUid);
                    if ($target instanceof ErrorResult) {
                        return $target;
                    }

                    $uidList = UidListParser::parse($uids);
                    $existingUids = $recordService->findExistingUids($tableName, $uidList);

                    if ($existingUids === []) {
                        throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
                    }

                    $skippedUids = array_values(array_diff($uidList, $existingUids));

                    $dataHandlerService->moveRecords($tableName, $existingUids, $target);

                    return new BatchRecordsMovedResult($existingUids, count($existingUids), $target, $skippedUids);
                }, arguments: [$uids, $targetPid, $afterUid], tableName: $tableName);
            },
            name: $toolName,
            description: 'Move multiple ' . $config['label'] . ' records to a new position in a single operation.'
                . ' Pass UIDs as comma-separated (e.g. "1,2,3").'
                . ' Provide exactly one of: targetPid (move all to the top of that page)'
                . ' or afterUid (place all after that sibling record).'
                . ' Non-existent UIDs are skipped and reported in skippedUids.',
        );
    }
}
