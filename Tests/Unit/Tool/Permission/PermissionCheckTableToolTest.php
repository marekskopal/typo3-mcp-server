<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Permission;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Tool\Permission\PermissionCheckTableTool;
use MarekSkopal\MsMcpServer\Tool\Result\TablePermissionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionCheckTableTool::class)]
final class PermissionCheckTableToolTest extends TestCase
{
    public function testExecuteReturnsTablePermissions(): void
    {
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->expects(self::once())
            ->method('checkTableAccess')
            ->with('pages')
            ->willReturn(['table' => 'pages', 'canSelect' => true, 'canModify' => true]);

        $tool = new PermissionCheckTableTool($permissionService);
        $result = $tool->execute('pages');

        self::assertInstanceOf(TablePermissionResult::class, $result);
        self::assertSame('pages', $result->table);
        self::assertTrue($result->canSelect);
        self::assertTrue($result->canModify);
    }

    public function testExecuteReturnsFalseForDisallowedTable(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('checkTableAccess')
            ->willReturn(['table' => 'sys_file', 'canSelect' => false, 'canModify' => false]);

        $tool = new PermissionCheckTableTool($permissionService);
        $result = $tool->execute('sys_file');

        self::assertInstanceOf(TablePermissionResult::class, $result);
        self::assertFalse($result->canSelect);
        self::assertFalse($result->canModify);
    }

    public function testExecuteThrowsExceptionWhenNoUser(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('checkTableAccess')
            ->willThrowException(new \RuntimeException('No authenticated backend user available', 1714000010));

        $tool = new PermissionCheckTableTool($permissionService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1714000010);

        $tool->execute('pages');
    }
}
