<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Dynamic;

use MarekSkopal\MsMcpServer\Repository\DiscoveredTableRepository;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolFactory;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

/**
 * Registers the nine-tool CRUD + batch surface for every table an administrator has opted in to,
 * either through `EXTCONF` or the extension-table discovery module.
 *
 * This class decides *which* tables get tools and resolves their field lists from TCA; the tools
 * themselves come from {@see TableToolFactory}, shared with the redirect and scheduler registrars.
 */
readonly class DynamicToolRegistrar
{
    /** Table-name prefixes that discovery never exposes; re-checked here in case a row was tampered with. */
    private const array EXCLUDED_TABLE_PREFIXES = ['sys_', 'be_', 'fe_', 'cache_', 'cf_', 'index_', 'tx_msmcpserver_'];

    private const array EXCLUDED_TABLES = ['pages', 'tt_content'];

    public function __construct(
        private TcaSchemaService $tcaSchemaService,
        private DiscoveredTableRepository $discoveredTableRepository,
        private LoggerInterface $logger,
        private TableToolFactory $tableToolFactory,
    ) {
    }

    public function register(Builder $builder): void
    {
        /** @var array<string, array{label: string, prefix: string, listFields?: list<string>, readFields?: list<string>, writableFields?: list<string>}> $tables */
        $tables = $this->getTablesConfiguration();

        foreach ($tables as $tableName => $tableConfig) {
            $config = $this->resolveConfig($tableName, $tableConfig);

            // A table absent from TCA resolves to no readable fields; registering tools for it would
            // produce a surface with nothing to return.
            if ($config->readFields === []) {
                continue;
            }

            $this->tableToolFactory->registerAll($builder, $config);
        }
    }

    /** @param array{label: string, prefix: string, listFields?: list<string>, readFields?: list<string>, writableFields?: list<string>} $tableConfig */
    private function resolveConfig(string $tableName, array $tableConfig): TableToolConfig
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig($tableName);
        $listFields = $tableConfig['listFields'] ?? $this->tcaSchemaService->getListFields($tableName);
        $readFields = $tableConfig['readFields'] ?? $this->tcaSchemaService->getReadFields($tableName);

        // A translation-aware table must return its language fields whatever the caller selected,
        // or the rows come back indistinguishable by language.
        foreach ([$translationConfig['languageField'], $translationConfig['transOrigPointerField']] as $field) {
            if ($field === null) {
                continue;
            }
            if (!in_array($field, $listFields, true)) {
                $listFields[] = $field;
            }
            if (!in_array($field, $readFields, true)) {
                $readFields[] = $field;
            }
        }

        return new TableToolConfig(
            tableName: $tableName,
            label: $tableConfig['label'],
            prefix: $tableConfig['prefix'],
            listFields: $listFields,
            readFields: $readFields,
            writableFields: $tableConfig['writableFields'] ?? $this->tcaSchemaService->getWritableFields($tableName),
            languageField: $translationConfig['languageField'],
            transOrigPointerField: $translationConfig['transOrigPointerField'],
        );
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
}
