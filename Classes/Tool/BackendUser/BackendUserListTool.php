<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\BackendUser;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\RowField;
use MarekSkopal\MsMcpServer\Tool\Result\BackendUserListResult;
use MarekSkopal\MsMcpServer\Tool\Result\BackendUserSummaryResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class BackendUserListTool
{
    private const SUMMARY_FIELDS = [
        'uid',
        'username',
        'realName',
        'email',
        'admin',
        'disable',
        'starttime',
        'endtime',
        'lastlogin',
    ];

    public function __construct(private RecordService $recordService, private PermissionService $permissionService,)
    {
    }

    #[McpTool(
        name: 'backend_user_list',
        description: 'List backend users (be_users). Restricted to admin backend users.'
            . ' Optional substring "search" matches against username (LIKE %search%).'
            . ' "activeOnly" excludes disabled accounts; "adminOnly" returns only admins.'
            . ' Soft-deleted accounts are always excluded. Sensitive fields (password, mfa) are never returned.',
    )]
    public function execute(
        string $search = '',
        bool $activeOnly = false,
        bool $adminOnly = false,
        int $limit = 20,
        int $offset = 0,
    ): BackendUserListResult {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        $conditions = [
            'deleted' => ['operator' => 'eq', 'value' => '0'],
        ];

        if ($search !== '') {
            $conditions['username'] = ['operator' => 'like', 'value' => $search];
        }
        if ($activeOnly) {
            $conditions['disable'] = ['operator' => 'eq', 'value' => '0'];
        }
        if ($adminOnly) {
            $conditions['admin'] = ['operator' => 'eq', 'value' => '1'];
        }

        $result = $this->recordService->search('be_users', $conditions, $limit, $offset, self::SUMMARY_FIELDS, null, 'username', 'ASC');

        $records = array_map(
            static fn(array $row): BackendUserSummaryResult => new BackendUserSummaryResult(
                uid: RowField::asInt($row, 'uid'),
                username: RowField::asString($row, 'username'),
                realName: RowField::asString($row, 'realName'),
                email: RowField::asString($row, 'email'),
                admin: RowField::asBool($row, 'admin'),
                disabled: RowField::asBool($row, 'disable'),
                starttime: RowField::asInt($row, 'starttime'),
                endtime: RowField::asInt($row, 'endtime'),
                lastlogin: RowField::asInt($row, 'lastlogin'),
            ),
            $result['records'],
        );

        return new BackendUserListResult($records, $result['total']);
    }
}
