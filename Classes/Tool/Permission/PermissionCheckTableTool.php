<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Permission;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Tool\Result\TablePermissionResult;
use Mcp\Capability\Attribute\McpTool;

readonly class PermissionCheckTableTool
{
    public function __construct(private PermissionService $permissionService)
    {
    }

    #[McpTool(
        name: 'permission_check_table',
        description: 'Check if the current user can read (select) and/or write (modify) a specific database table.'
            . ' Use this before record operations to verify access.',
    )]
    public function execute(string $tableName): TablePermissionResult
    {
        $result = $this->permissionService->checkTableAccess($tableName);

        return new TablePermissionResult(table: $result['table'], canSelect: $result['canSelect'], canModify: $result['canModify']);
    }
}
