<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locale;

readonly class ExtensionTableDiscoveryService
{
    private const array EXCLUDED_PREFIXES = ['sys_', 'be_', 'fe_', 'cache_', 'cf_', 'index_', 'tx_msmcpserver_'];

    private const array EXCLUDED_TABLES = ['pages', 'tt_content'];

    public function __construct(private LanguageServiceFactory $languageServiceFactory)
    {
    }

    /**
     * Scans TCA for extension tables not already configured via EXTCONF.
     *
     * @return array<string, array{label: string, prefix: string}>
     */
    public function discoverTables(): array
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return [];
        }

        $extconfTables = $this->getExtconfTables();
        $discovered = [];

        foreach (array_keys($tca) as $tableName) {
            if (!is_string($tableName)) {
                continue;
            }

            if (!$this->isExtensionTable($tableName)) {
                continue;
            }

            if (array_key_exists($tableName, $extconfTables)) {
                continue;
            }

            $discovered[$tableName] = [
                'label' => $this->generateLabel($tableName),
                'prefix' => $this->generatePrefix($tableName),
            ];
        }

        return $discovered;
    }

    /**
     * Generates a human-readable label from TCA title or table name.
     */
    public function generateLabel(string $tableName): string
    {
        $tcaAll = $GLOBALS['TCA'] ?? [];
        if (!is_array($tcaAll)) {
            return $this->humanizeTableName($tableName);
        }

        $tca = $tcaAll[$tableName] ?? null;
        if (!is_array($tca)) {
            return $this->humanizeTableName($tableName);
        }

        $ctrl = $tca['ctrl'] ?? [];
        $title = is_array($ctrl) ? ($ctrl['title'] ?? null) : null;
        if (!is_string($title) || $title === '') {
            return $this->humanizeTableName($tableName);
        }

        if (!str_starts_with($title, 'LLL:')) {
            return $title;
        }

        $resolved = $this->resolveLanguageLabel($title);
        if ($resolved !== '' && $resolved !== $title) {
            return $resolved;
        }

        return $this->humanizeTableName($tableName);
    }

    /**
     * Generates a tool name prefix from a table name.
     *
     * Examples:
     * - tx_news_domain_model_news → news
     * - tx_blog_domain_model_post → blog_post
     * - tx_myext_table → myext_table
     */
    public function generatePrefix(string $tableName): string
    {
        $name = $tableName;

        // Strip tx_ prefix
        if (str_starts_with($name, 'tx_')) {
            $name = substr($name, 3);
        }

        // Handle _domain_model_ convention
        if (str_contains($name, '_domain_model_')) {
            $parts = explode('_domain_model_', $name, 2);
            $extKey = $parts[0];
            $modelName = $parts[1];

            if ($extKey === $modelName) {
                return $modelName;
            }

            return $extKey . '_' . $modelName;
        }

        return $name;
    }

    private function isExtensionTable(string $tableName): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($tableName, $prefix)) {
                return false;
            }
        }

        if (in_array($tableName, self::EXCLUDED_TABLES, true)) {
            return false;
        }

        // Only consider tables with tx_ prefix (standard TYPO3 extension tables)
        return str_starts_with($tableName, 'tx_');
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

    private function resolveLanguageLabel(string $label): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            return $languageService->sL($label);
        }

        return $this->languageServiceFactory->create(new Locale('en'))->sL($label);
    }

    private function humanizeTableName(string $tableName): string
    {
        $name = $tableName;

        if (str_starts_with($name, 'tx_')) {
            $name = substr($name, 3);
        }

        if (str_contains($name, '_domain_model_')) {
            $parts = explode('_domain_model_', $name, 2);

            return ucwords(str_replace('_', ' ', $parts[0])) . ' ' . ucwords(str_replace('_', ' ', $parts[1]));
        }

        return ucwords(str_replace('_', ' ', $name));
    }
}
