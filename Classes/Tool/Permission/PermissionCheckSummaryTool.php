<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Permission;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Tool\Result\PermissionSummaryResult;
use Mcp\Capability\Attribute\McpTool;

readonly class PermissionCheckSummaryTool
{
    public function __construct(private PermissionService $permissionService)
    {
    }

    #[McpTool(
        name: 'permission_check_summary',
        description: 'Get a summary of the current user\'s permissions: admin status, allowed tables for reading and writing,'
            . ' allowed languages, file permissions, and mount points.'
            . ' For admin users, table/language lists may be empty as admins have unrestricted access.',
    )]
    public function execute(): PermissionSummaryResult
    {
        $summary = $this->permissionService->getPermissionSummary();

        return new PermissionSummaryResult(
            isAdmin: $summary['isAdmin'],
            tablesSelect: $summary['tablesSelect'],
            tablesModify: $summary['tablesModify'],
            allowedLanguages: $summary['allowedLanguages'],
            filePermissions: $summary['filePermissions'],
            webmounts: $summary['webmounts'],
            filemounts: $summary['filemounts'],
        );
    }
}
