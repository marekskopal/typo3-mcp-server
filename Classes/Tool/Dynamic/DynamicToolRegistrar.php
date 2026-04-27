<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Dynamic;

use MarekSkopal\MsMcpServer\Repository\DiscoveredTableRepository;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
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
    public function __construct(
        private RecordService $recordService,
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private DiscoveredTableRepository $discoveredTableRepository,
        private LoggerInterface $logger,
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
        foreach ($rows as $row) {
            $tables[$row['table_name']] = [
                'label' => $row['label'],
                'prefix' => $row['prefix'],
            ];
        }

        return $tables;
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerListTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;
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
                    $tableName,
                    $defaultFields,
                    $readFields,
                    $languageField,
                    $resolveFields,
                ): string {
                    /** @var list<string> $fields */
                    $fields = $resolveFields($selectFields, $defaultFields, $readFields);

                    if (!in_array($languageField, $fields, true)) {
                        $fields[] = $languageField;
                    }

                    try {
                        $result = $recordService->findByPid(
                            $tableName,
                            $pid,
                            $limit,
                            $offset,
                            $fields,
                            $sysLanguageUid >= 0 ? $sysLanguageUid : null,
                            $sysLanguageUid >= 0 ? $languageField : null,
                        );
                    } catch (\Throwable $e) {
                        $logger->error($tableName . ' list tool failed', ['exception' => $e]);

                        throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                    }

                    return json_encode($result, JSON_THROW_ON_ERROR);
                },
                name: $config['prefix'] . '_list',
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
                    $tableName,
                    $defaultFields,
                    $readFields,
                    $resolveFields,
                ): string {
                    /** @var list<string> $fields */
                    $fields = $resolveFields($selectFields, $defaultFields, $readFields);

                    try {
                        $result = $recordService->findByPid($tableName, $pid, $limit, $offset, $fields);
                    } catch (\Throwable $e) {
                        $logger->error($tableName . ' list tool failed', ['exception' => $e]);

                        throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                    }

                    return json_encode($result, JSON_THROW_ON_ERROR);
                },
                name: $config['prefix'] . '_list',
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
        $fields = $config['readFields'];
        $label = $config['label'];
        $languageField = $config['translationConfig']['languageField'];
        $transOrigPointerField = $config['translationConfig']['transOrigPointerField'];

        $builder->addTool(
            handler: static function (int $uid) use ($recordService, $logger, $tableName, $fields, $label, $languageField, $transOrigPointerField): string {
                try {
                    $record = $recordService->findByUid($tableName, $uid, $fields);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' get tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

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
                    $record['translations'] = $recordService->findTranslations($tableName, $uid, $languageField, $transOrigPointerField);
                }

                return json_encode($record, JSON_THROW_ON_ERROR);
            },
            name: $config['prefix'] . '_get',
            description: 'Get a single ' . $config['label'] . ' record by its uid.',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerCreateTool(Builder $builder, string $tableName, array $config): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;
        $writableFields = $config['writableFields'];
        $languageField = $config['translationConfig']['languageField'];

        /** @param array<string, mixed> $data */
        $createHandler = static function (
            array $data,
            int $pid,
            int $sysLanguageUid,
        ) use (
            $dataHandlerService,
            $logger,
            $tableName,
            $writableFields,
            $languageField,
        ): RecordCreatedResult|ErrorResult {
            $filteredData = array_intersect_key($data, array_flip($writableFields));

            if ($languageField !== null) {
                $filteredData[$languageField] = $sysLanguageUid;
                unset($data[$languageField]);
            }

            $ignoredFields = array_map('strval', array_values(array_diff(array_keys($data), array_keys($filteredData))));

            if ($filteredData === []) {
                return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
            }

            try {
                $uid = $dataHandlerService->createRecord($tableName, $pid, $filteredData);
            } catch (\Throwable $e) {
                $logger->error($tableName . ' create tool failed', ['exception' => $e]);

                throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return new RecordCreatedResult($uid, $ignoredFields);
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
                    /** @var array<string, mixed> $data */
                    $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

                    return $createHandler($data, $pid, $sysLanguageUid);
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
                    /** @var array<string, mixed> $data */
                    $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

                    return $createHandler($data, $pid, 0);
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
        $writableFields = $config['writableFields'];

        $builder->addTool(
            handler: static function (
                int $uid,
                string $fields,
            ) use (
                $dataHandlerService,
                $logger,
                $tableName,
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
                    $dataHandlerService->updateRecord($tableName, $uid, $filteredData);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' update tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new RecordUpdatedResult($uid, array_keys($filteredData), $ignoredFields);
            },
            name: $config['prefix'] . '_update',
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

        $builder->addTool(
            handler: static function (int $uid) use ($dataHandlerService, $logger, $tableName): RecordDeletedResult {
                try {
                    $dataHandlerService->deleteRecord($tableName, $uid);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' delete tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new RecordDeletedResult($uid);
            },
            name: $config['prefix'] . '_delete',
            description: 'Delete a ' . $config['label'] . ' record by its uid.',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerMoveTool(Builder $builder, string $tableName, array $config): void
    {
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (int $uid, int $target) use ($dataHandlerService, $logger, $tableName): RecordMovedResult {
                try {
                    $dataHandlerService->moveRecord($tableName, $uid, $target);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' move tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new RecordMovedResult($uid, $target);
            },
            name: $config['prefix'] . '_move',
            description: 'Move a ' . $config['label'] . ' record to a new position.'
                . ' Use a positive target to move to the top of a page (target = page pid).'
                . ' Use a negative target to move after another record (target = -uid of the record to place after).',
        );
    }

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerDeleteBatchTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $dataHandlerService = $this->dataHandlerService;
        $logger = $this->logger;

        $builder->addTool(
            handler: static function (string $uids) use ($recordService, $dataHandlerService, $logger, $tableName): BatchRecordsDeletedResult {
                $uidList = self::parseUids($uids);
                $existingUids = $recordService->findExistingUids($tableName, $uidList);

                if ($existingUids === []) {
                    throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
                }

                $skippedUids = array_values(array_diff($uidList, $existingUids));

                try {
                    $dataHandlerService->deleteRecords($tableName, $existingUids);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' delete batch tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new BatchRecordsDeletedResult($existingUids, count($existingUids), $skippedUids);
            },
            name: $config['prefix'] . '_delete_batch',
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
        $writableFields = $config['writableFields'];

        $builder->addTool(
            handler: static function (
                string $uids,
                string $fields,
            ) use (
                $recordService,
                $dataHandlerService,
                $logger,
                $tableName,
                $writableFields,
            ): BatchRecordsUpdatedResult {
                $uidList = self::parseUids($uids);
                $existingUids = $recordService->findExistingUids($tableName, $uidList);

                if ($existingUids === []) {
                    throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
                }

                $skippedUids = array_values(array_diff($uidList, $existingUids));

                /** @var array<string, mixed> $fieldData */
                $fieldData = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

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

                try {
                    $dataHandlerService->updateRecords($tableName, $existingUids, $validFields);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' update batch tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new BatchRecordsUpdatedResult(
                    $existingUids,
                    count($existingUids),
                    array_keys($validFields),
                    $ignoredFields,
                    $skippedUids,
                );
            },
            name: $config['prefix'] . '_update_batch',
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

        $builder->addTool(
            handler: static function (
                string $uids,
                int $target,
            ) use (
                $recordService,
                $dataHandlerService,
                $logger,
                $tableName
            ): BatchRecordsMovedResult {
                $uidList = self::parseUids($uids);
                $existingUids = $recordService->findExistingUids($tableName, $uidList);

                if ($existingUids === []) {
                    throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
                }

                $skippedUids = array_values(array_diff($uidList, $existingUids));

                try {
                    $dataHandlerService->moveRecords($tableName, $existingUids, $target);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' move batch tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return new BatchRecordsMovedResult($existingUids, count($existingUids), $target, $skippedUids);
            },
            name: $config['prefix'] . '_move_batch',
            description: 'Move multiple ' . $config['label'] . ' records to a new position in a single operation.'
                . ' Pass UIDs as comma-separated (e.g. "1,2,3").'
                . ' Positive target = move to page (target = pid). Negative target = move after record (target = -uid).'
                . ' Non-existent UIDs are skipped and reported in skippedUids.',
        );
    }

    /** @return list<int> */
    private static function parseUids(string $uids): array
    {
        return array_values(array_filter(
            array_map('intval', array_filter(explode(',', $uids), static fn(string $v): bool => $v !== '')),
            static fn(int $v): bool => $v > 0,
        ));
    }
}
