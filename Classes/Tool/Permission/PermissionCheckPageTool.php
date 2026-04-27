<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Permission;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\PagePermissionResult;
use Mcp\Capability\Attribute\McpTool;

readonly class PermissionCheckPageTool
{
    public function __construct(private PermissionService $permissionService, private RecordService $recordService,)
    {
    }

    #[McpTool(
        name: 'permission_check_page',
        description: 'Check what the current user can do on a specific page: show, edit, delete, create subpages,'
            . ' and edit content. Use this before page or content operations.',
    )]
    public function execute(int $pageId): PagePermissionResult|ErrorResult
    {
        $pageRow = $this->recordService->findByUid(
            'pages',
            $pageId,
            ['uid', 'pid', 'perms_userid', 'perms_user', 'perms_groupid', 'perms_group', 'perms_everybody'],
        );

        if ($pageRow === null) {
            return new ErrorResult('Page not found', ['pageId' => $pageId]);
        }

        $result = $this->permissionService->checkPageAccess($pageRow);

        return new PagePermissionResult(
            pageId: $result['pageId'],
            canShow: $result['canShow'],
            canEdit: $result['canEdit'],
            canDelete: $result['canDelete'],
            canCreateSubpages: $result['canCreateSubpages'],
            canEditContent: $result['canEditContent'],
            permissionBitmask: $result['permissionBitmask'],
        );
    }
}
