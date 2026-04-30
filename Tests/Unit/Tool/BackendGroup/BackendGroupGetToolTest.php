<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\BackendGroup;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\BackendGroup\BackendGroupGetTool;
use MarekSkopal\MsMcpServer\Tool\Result\BackendGroupDetailResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackendGroupGetTool::class)]
final class BackendGroupGetToolTest extends TestCase
{
    public function testExecuteReturnsDetailForAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('be_groups', 3, self::isType('array'))
            ->willReturn([
                'uid' => 3,
                'title' => 'Power Editors',
                'description' => 'All-access editors',
                'hidden' => 0,
                'deleted' => 0,
                'subgroup' => '1',
                'db_mountpoints' => '0',
                'file_mountpoints' => '',
                'file_permissions' => 'readFile,writeFile',
                'workspace_perms' => 1,
                'pagetypes_select' => '1,2,3',
                'tables_modify' => 'pages,tt_content',
                'tables_select' => 'pages,tt_content,sys_file',
                'non_exclude_fields' => '',
                'explicit_allowdeny' => '',
                'allowed_languages' => '0,1',
                'custom_options' => '',
                'groupMods' => 'web_list',
                'mfa_providers' => 'totp',
                'TSconfig' => '',
                'tsconfig_includes' => '',
            ]);

        $tool = new BackendGroupGetTool($recordService, $permissionService);
        $result = $tool->execute(3);

        self::assertInstanceOf(BackendGroupDetailResult::class, $result);
        self::assertSame(3, $result->uid);
        self::assertSame('Power Editors', $result->title);
        self::assertSame([1], $result->subgroup);
        self::assertSame('pages,tt_content', $result->tablesModify);
        self::assertSame('totp', $result->mfaProviders);
    }

    public function testExecuteReturnsErrorForMissingUid(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $tool = new BackendGroupGetTool($recordService, $permissionService);
        $result = $tool->execute(999);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('Backend group not found', $result->error);
    }

    public function testExecuteReturnsErrorForSoftDeletedGroup(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(true);

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn([
            'uid' => 9,
            'title' => 'Removed',
            'deleted' => 1,
        ]);

        $tool = new BackendGroupGetTool($recordService, $permissionService);
        $result = $tool->execute(9);

        self::assertInstanceOf(ErrorResult::class, $result);
    }

    public function testExecuteThrowsForNonAdmin(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn(false);

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('findByUid');

        $tool = new BackendGroupGetTool($recordService, $permissionService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Admin access required');

        $tool->execute(1);
    }
}
