<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\BackendUser;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\BackendUser\BackendUserListTool;
use MarekSkopal\MsMcpServer\Tool\Result\BackendUserListResult;
use MarekSkopal\MsMcpServer\Tool\Result\BackendUserSummaryResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackendUserListTool::class)]
final class BackendUserListToolTest extends TestCase
{
    public function testExecuteReturnsListForAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'be_users',
                ['deleted' => ['operator' => 'eq', 'value' => '0']],
                20,
                0,
                self::callback(static fn(array $fields): bool => in_array('username', $fields, true)
                    && !in_array('password', $fields, true)
                    && !in_array('mfa', $fields, true)),
                null,
                'username',
                'ASC',
            )
            ->willReturn([
                'records' => [
                    [
                        'uid' => 1,
                        'username' => 'admin',
                        'realName' => 'Site Admin',
                        'email' => 'admin@example.com',
                        'admin' => 1,
                        'disable' => 0,
                        'starttime' => 0,
                        'endtime' => 0,
                        'lastlogin' => 1700000000,
                    ],
                ],
                'total' => 1,
            ]);

        $tool = new BackendUserListTool($recordService, $permissionService);
        $result = $tool->execute();

        self::assertInstanceOf(BackendUserListResult::class, $result);
        self::assertSame(1, $result->total);
        self::assertCount(1, $result->records);
        $first = $result->records[0];
        self::assertInstanceOf(BackendUserSummaryResult::class, $first);
        self::assertSame(1, $first->uid);
        self::assertSame('admin', $first->username);
        self::assertTrue($first->admin);
        self::assertFalse($first->disabled);
        self::assertSame(1700000000, $first->lastlogin);
    }

    public function testExecuteThrowsForNonAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(false);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('search');

        $tool = new BackendUserListTool($recordService, $permissionService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Admin access required');

        $tool->execute();
    }

    public function testExecuteAppliesSearchActiveAndAdminFilters(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'be_users',
                [
                    'deleted' => ['operator' => 'eq', 'value' => '0'],
                    'username' => ['operator' => 'like', 'value' => 'jo'],
                    'disable' => ['operator' => 'eq', 'value' => '0'],
                    'admin' => ['operator' => 'eq', 'value' => '1'],
                ],
                10,
                5,
                self::isType('array'),
                null,
                'username',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new BackendUserListTool($recordService, $permissionService);
        $tool->execute(search: 'jo', activeOnly: true, adminOnly: true, limit: 10, offset: 5);
    }
}
