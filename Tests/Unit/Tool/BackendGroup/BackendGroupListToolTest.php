<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\BackendGroup;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\BackendGroup\BackendGroupListTool;
use MarekSkopal\MsMcpServer\Tool\Result\BackendGroupListResult;
use MarekSkopal\MsMcpServer\Tool\Result\BackendGroupSummaryResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackendGroupListTool::class)]
final class BackendGroupListToolTest extends TestCase
{
    public function testExecuteReturnsListForAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'be_groups',
                ['deleted' => ['operator' => 'eq', 'value' => '0']],
                20,
                0,
                self::isType('array'),
                null,
                'title',
                'ASC',
            )
            ->willReturn([
                'records' => [
                    [
                        'uid' => 7,
                        'title' => 'Editors',
                        'description' => 'Content editors',
                        'hidden' => 0,
                        'subgroup' => '1,2',
                    ],
                ],
                'total' => 1,
            ]);

        $tool = new BackendGroupListTool($recordService, $permissionService);
        $result = $tool->execute();

        self::assertInstanceOf(BackendGroupListResult::class, $result);
        self::assertSame(1, $result->total);
        self::assertCount(1, $result->records);
        $first = $result->records[0];
        self::assertInstanceOf(BackendGroupSummaryResult::class, $first);
        self::assertSame(7, $first->uid);
        self::assertSame('Editors', $first->title);
        self::assertFalse($first->hidden);
        self::assertSame([1, 2], $first->subgroup);
    }

    public function testExecuteAppliesSearchFilter(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'be_groups',
                [
                    'deleted' => ['operator' => 'eq', 'value' => '0'],
                    'title' => ['operator' => 'like', 'value' => 'edit'],
                ],
                10,
                5,
                self::isType('array'),
                null,
                'title',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new BackendGroupListTool($recordService, $permissionService);
        $tool->execute(search: 'edit', limit: 10, offset: 5);
    }

    public function testExecuteThrowsForNonAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(false);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('search');

        $tool = new BackendGroupListTool($recordService, $permissionService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Admin access required');

        $tool->execute();
    }
}
