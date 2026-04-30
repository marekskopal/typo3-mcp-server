<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\BackendGroup;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\RowField;
use MarekSkopal\MsMcpServer\Tool\Result\BackendGroupListResult;
use MarekSkopal\MsMcpServer\Tool\Result\BackendGroupSummaryResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class BackendGroupListTool
{
    private const SUMMARY_FIELDS = [
        'uid',
        'title',
        'description',
        'hidden',
        'subgroup',
    ];

    public function __construct(private RecordService $recordService, private PermissionService $permissionService,)
    {
    }

    #[McpTool(
        name: 'backend_group_list',
        description: 'List backend user groups (be_groups). Restricted to admin backend users.'
            . ' Optional substring "search" matches against title (LIKE %search%).'
            . ' Soft-deleted groups are always excluded.',
    )]
    public function execute(string $search = '', int $limit = 20, int $offset = 0,): BackendGroupListResult
    {
        if (!$this->permissionService->isAdmin()) {
            throw new ToolCallException('Admin access required');
        }

        $conditions = [
            'deleted' => ['operator' => 'eq', 'value' => '0'],
        ];

        if ($search !== '') {
            $conditions['title'] = ['operator' => 'like', 'value' => $search];
        }

        $result = $this->recordService->search('be_groups', $conditions, $limit, $offset, self::SUMMARY_FIELDS, null, 'title', 'ASC');

        $records = array_map(
            static fn(array $row): BackendGroupSummaryResult => new BackendGroupSummaryResult(
                uid: RowField::asInt($row, 'uid'),
                title: RowField::asString($row, 'title'),
                description: RowField::asString($row, 'description'),
                hidden: RowField::asBool($row, 'hidden'),
                subgroup: RowField::asIntList($row, 'subgroup'),
            ),
            $result['records'],
        );

        return new BackendGroupListResult($records, $result['total']);
    }
}
