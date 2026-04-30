<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\BackendUser;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\BackendUser\BackendUserGetTool;
use MarekSkopal\MsMcpServer\Tool\Result\BackendUserDetailResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackendUserGetTool::class)]
final class BackendUserGetToolTest extends TestCase
{
    public function testExecuteReturnsDetailForAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with(
                'be_users',
                42,
                self::callback(static fn(array $fields): bool => in_array('username', $fields, true)
                    && in_array('usergroup', $fields, true)
                    && !in_array('password', $fields, true)
                    && !in_array('mfa', $fields, true)),
            )
            ->willReturn([
                'uid' => 42,
                'username' => 'editor',
                'realName' => 'The Editor',
                'email' => 'editor@example.com',
                'admin' => 0,
                'disable' => 0,
                'deleted' => 0,
                'starttime' => 0,
                'endtime' => 0,
                'lastlogin' => 1700000000,
                'usergroup' => '1,3,5',
                'lang' => 'en',
                'description' => 'Notes',
                'db_mountpoints' => '0',
                'file_mountpoints' => '',
                'file_permissions' => 'readFile,writeFile',
                'workspace_perms' => 1,
                'options' => 3,
                'userMods' => 'web_list',
                'allowed_languages' => '0,1',
                'TSconfig' => 'options.foo = 1',
                'category_perms' => '',
            ]);

        $tool = new BackendUserGetTool($recordService, $permissionService);
        $result = $tool->execute(42);

        self::assertInstanceOf(BackendUserDetailResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame('editor', $result->username);
        self::assertSame([1, 3, 5], $result->usergroup);
        self::assertSame('en', $result->lang);
        self::assertSame(1, $result->workspacePerms);
        self::assertSame('options.foo = 1', $result->tsConfig);
    }

    public function testExecuteReturnsErrorForMissingUid(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $tool = new BackendUserGetTool($recordService, $permissionService);
        $result = $tool->execute(999);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('Backend user not found', $result->error);
        self::assertSame(['uid' => 999], $result->context);
    }

    public function testExecuteReturnsErrorForSoftDeletedUser(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn([
            'uid' => 5,
            'username' => 'gone',
            'deleted' => 1,
        ]);

        $tool = new BackendUserGetTool($recordService, $permissionService);
        $result = $tool->execute(5);

        self::assertInstanceOf(ErrorResult::class, $result);
    }

    public function testExecuteThrowsForNonAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(false);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('findByUid');

        $tool = new BackendUserGetTool($recordService, $permissionService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Admin access required');

        $tool->execute(1);
    }
}
