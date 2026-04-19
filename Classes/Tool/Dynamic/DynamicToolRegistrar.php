<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Dynamic;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
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

    /** @param array{label: string, prefix: string, listFields: list<string>, readFields: list<string>, writableFields: list<string>, translationConfig: array{languageField: string|null, transOrigPointerField: string|null, translationSource: string|null}} $config */
    private function registerListTool(Builder $builder, string $tableName, array $config): void
    {
        $recordService = $this->recordService;
        $logger = $this->logger;
        $fields = $config['listFields'];
        $languageField = $config['translationConfig']['languageField'];

        if ($languageField !== null) {
            $builder->addTool(
                handler: static function (
                    int $pid = 0,
                    int $limit = 20,
                    int $offset = 0,
                    int $sysLanguageUid = -1,
                ) use (
                    $recordService,
                    $logger,
                    $tableName,
                    $fields,
                    $languageField,
                ): string {
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
                    . ' Use sysLanguageUid to filter by language (0 = default, -1 = all).',
            );
        } else {
            $builder->addTool(
                handler: static function (
                    int $pid = 0,
                    int $limit = 20,
                    int $offset = 0,
                ) use (
                    $recordService,
                    $logger,
                    $tableName,
                    $fields,
                ): string {
                    try {
                        $result = $recordService->findByPid($tableName, $pid, $limit, $offset, $fields);
                    } catch (\Throwable $e) {
                        $logger->error($tableName . ' list tool failed', ['exception' => $e]);

                        throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                    }

                    return json_encode($result, JSON_THROW_ON_ERROR);
                },
                name: $config['prefix'] . '_list',
                description: 'List ' . $config['label'] . ' records by parent page ID with pagination.',
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

        $builder->addTool(
            handler: static function (
                int $pid,
                string $fields,
            ) use (
                $dataHandlerService,
                $logger,
                $tableName,
                $writableFields,
            ): string {
                /** @var array<string, mixed> $data */
                $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

                $filteredData = array_intersect_key($data, array_flip($writableFields));
                if ($filteredData === []) {
                    return json_encode(['error' => 'No valid fields provided'], JSON_THROW_ON_ERROR);
                }

                try {
                    $uid = $dataHandlerService->createRecord($tableName, $pid, $filteredData);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' create tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return json_encode(['uid' => $uid], JSON_THROW_ON_ERROR);
            },
            name: $config['prefix'] . '_create',
            description: 'Create a new ' . $config['label'] . ' record. Pass fields as a JSON object string.'
                . ' Available fields: ' . implode(', ', $config['writableFields']) . '.',
        );
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
            ): string {
                /** @var array<string, mixed> $data */
                $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

                $filteredData = array_intersect_key($data, array_flip($writableFields));
                if ($filteredData === []) {
                    return json_encode(['error' => 'No valid fields provided'], JSON_THROW_ON_ERROR);
                }

                try {
                    $dataHandlerService->updateRecord($tableName, $uid, $filteredData);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' update tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return json_encode(['uid' => $uid, 'updated' => array_keys($filteredData)], JSON_THROW_ON_ERROR);
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
            handler: static function (int $uid) use ($dataHandlerService, $logger, $tableName): string {
                try {
                    $dataHandlerService->deleteRecord($tableName, $uid);
                } catch (\Throwable $e) {
                    $logger->error($tableName . ' delete tool failed', ['exception' => $e]);

                    throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
                }

                return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
            },
            name: $config['prefix'] . '_delete',
            description: 'Delete a ' . $config['label'] . ' record by its uid.',
        );
    }
}
