<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Resource\Result\TcaTableEntry;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use Mcp\Capability\Attribute\McpResource;
use const JSON_THROW_ON_ERROR;

readonly class TcaTablesResource
{
    public function __construct(private PermissionService $permissionService)
    {
    }

    #[McpResource(
        uri: 'typo3://schema/tables',
        name: 'tca_tables',
        description: 'List of all available TCA database tables with their labels.',
        mimeType: 'application/json',
    )]
    public function execute(): string
    {
        /** @var array<string, array{ctrl?: array{title?: string}}> $tca */
        $tca = $GLOBALS['TCA'] ?? [];
        $tables = [];

        foreach ($tca as $tableName => $config) {
            // Listing every TCA table regardless of `tables_select` handed a non-admin a map of
            // tables they cannot read — `be_groups`, `sys_*` — which the backend never shows them.
            // Admins pass this check unconditionally, so their listing is unchanged.
            if (!$this->permissionService->canSelectTable($tableName)) {
                continue;
            }

            $tables[] = new TcaTableEntry(table: $tableName, label: $config['ctrl']['title'] ?? $tableName);
        }

        return json_encode($tables, JSON_THROW_ON_ERROR);
    }
}
