<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\BackendUser;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\RowField;
use MarekSkopal\MsMcpServer\Tool\Result\BackendUserDetailResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class BackendUserGetTool
{
    private const DETAIL_FIELDS = [
        'uid',
        'username',
        'realName',
        'email',
        'admin',
        'disable',
        'deleted',
        'starttime',
        'endtime',
        'lastlogin',
        'usergroup',
        'lang',
        'description',
        'db_mountpoints',
        'file_mountpoints',
        'file_permissions',
        'workspace_perms',
        'options',
        'userMods',
        'allowed_languages',
        'TSconfig',
        'category_perms',
    ];

    public function __construct(private RecordService $recordService, private PermissionService $permissionService,)
    {
    }

    #[McpTool(
        name: 'backend_user_get',
        description: 'Get a single backend user (be_users) by uid. Restricted to admin backend users.'
            . ' Returns an error result for soft-deleted or missing users.'
            . ' Sensitive fields (password, mfa) are never returned.',
    )]
    public function execute(int $uid): BackendUserDetailResult|ErrorResult
    {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        $row = $this->recordService->findByUid('be_users', $uid, self::DETAIL_FIELDS);

        if ($row === null || RowField::asInt($row, 'deleted') === 1) {
            return new ErrorResult('Backend user not found', ['uid' => $uid]);
        }

        return new BackendUserDetailResult(
            uid: RowField::asInt($row, 'uid'),
            username: RowField::asString($row, 'username'),
            realName: RowField::asString($row, 'realName'),
            email: RowField::asString($row, 'email'),
            admin: RowField::asBool($row, 'admin'),
            disabled: RowField::asBool($row, 'disable'),
            starttime: RowField::asInt($row, 'starttime'),
            endtime: RowField::asInt($row, 'endtime'),
            lastlogin: RowField::asInt($row, 'lastlogin'),
            usergroup: RowField::asIntList($row, 'usergroup'),
            lang: RowField::asString($row, 'lang'),
            description: RowField::asString($row, 'description'),
            dbMountpoints: RowField::asString($row, 'db_mountpoints'),
            fileMountpoints: RowField::asString($row, 'file_mountpoints'),
            filePermissions: RowField::asString($row, 'file_permissions'),
            workspacePerms: RowField::asInt($row, 'workspace_perms'),
            options: RowField::asInt($row, 'options'),
            userMods: RowField::asString($row, 'userMods'),
            allowedLanguages: RowField::asString($row, 'allowed_languages'),
            tsConfig: RowField::asString($row, 'TSconfig'),
            categoryPerms: RowField::asString($row, 'category_perms'),
        );
    }
}
