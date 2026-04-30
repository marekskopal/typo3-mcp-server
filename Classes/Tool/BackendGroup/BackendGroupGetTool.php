<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\BackendGroup;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\RowField;
use MarekSkopal\MsMcpServer\Tool\Result\BackendGroupDetailResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class BackendGroupGetTool
{
    private const DETAIL_FIELDS = [
        'uid',
        'title',
        'description',
        'hidden',
        'deleted',
        'subgroup',
        'db_mountpoints',
        'file_mountpoints',
        'file_permissions',
        'workspace_perms',
        'pagetypes_select',
        'tables_modify',
        'tables_select',
        'non_exclude_fields',
        'explicit_allowdeny',
        'allowed_languages',
        'custom_options',
        'groupMods',
        'mfa_providers',
        'TSconfig',
        'tsconfig_includes',
    ];

    public function __construct(private RecordService $recordService, private PermissionService $permissionService,)
    {
    }

    #[McpTool(
        name: 'backend_group_get',
        description: 'Get a single backend user group (be_groups) by uid. Restricted to admin backend users.'
            . ' Returns an error result for soft-deleted or missing groups.',
    )]
    public function execute(int $uid): BackendGroupDetailResult|ErrorResult
    {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        $row = $this->recordService->findByUid('be_groups', $uid, self::DETAIL_FIELDS);

        if ($row === null || RowField::asInt($row, 'deleted') === 1) {
            return new ErrorResult('Backend group not found', ['uid' => $uid]);
        }

        return new BackendGroupDetailResult(
            uid: RowField::asInt($row, 'uid'),
            title: RowField::asString($row, 'title'),
            description: RowField::asString($row, 'description'),
            hidden: RowField::asBool($row, 'hidden'),
            subgroup: RowField::asIntList($row, 'subgroup'),
            dbMountpoints: RowField::asString($row, 'db_mountpoints'),
            fileMountpoints: RowField::asString($row, 'file_mountpoints'),
            filePermissions: RowField::asString($row, 'file_permissions'),
            workspacePerms: RowField::asInt($row, 'workspace_perms'),
            pagetypesSelect: RowField::asString($row, 'pagetypes_select'),
            tablesModify: RowField::asString($row, 'tables_modify'),
            tablesSelect: RowField::asString($row, 'tables_select'),
            nonExcludeFields: RowField::asString($row, 'non_exclude_fields'),
            explicitAllowdeny: RowField::asString($row, 'explicit_allowdeny'),
            allowedLanguages: RowField::asString($row, 'allowed_languages'),
            customOptions: RowField::asString($row, 'custom_options'),
            groupMods: RowField::asString($row, 'groupMods'),
            mfaProviders: RowField::asString($row, 'mfa_providers'),
            tsConfig: RowField::asString($row, 'TSconfig'),
            tsconfigIncludes: RowField::asString($row, 'tsconfig_includes'),
        );
    }
}
