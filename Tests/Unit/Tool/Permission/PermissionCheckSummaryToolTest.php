<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Permission;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Tool\Permission\PermissionCheckSummaryTool;
use MarekSkopal\MsMcpServer\Tool\Result\PermissionSummaryResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PermissionCheckSummaryTool::class)]
final class PermissionCheckSummaryToolTest extends TestCase
{
    public function testExecuteReturnsSummary(): void
    {
        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->expects(self::once())
            ->method('getPermissionSummary')
            ->willReturn([
                'isAdmin' => false,
                'tablesSelect' => ['pages', 'tt_content'],
                'tablesModify' => ['pages'],
                'allowedLanguages' => [0, 1],
                'filePermissions' => ['readFile' => true, 'addFile' => false],
                'webmounts' => [1, 5],
                'filemounts' => [3],
            ]);

        $tool = new PermissionCheckSummaryTool($permissionService);
        $result = $tool->execute();

        self::assertInstanceOf(PermissionSummaryResult::class, $result);
        self::assertFalse($result->isAdmin);
        self::assertSame(['pages', 'tt_content'], $result->tablesSelect);
        self::assertSame(['pages'], $result->tablesModify);
        self::assertSame([0, 1], $result->allowedLanguages);
        self::assertSame(['readFile' => true, 'addFile' => false], $result->filePermissions);
        self::assertSame([1, 5], $result->webmounts);
        self::assertSame([3], $result->filemounts);
    }

    public function testExecuteReturnsAdminSummaryWithEmptyLists(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('getPermissionSummary')
            ->willReturn([
                'isAdmin' => true,
                'tablesSelect' => [],
                'tablesModify' => [],
                'allowedLanguages' => [],
                'filePermissions' => [],
                'webmounts' => [],
                'filemounts' => [],
            ]);

        $tool = new PermissionCheckSummaryTool($permissionService);
        $result = $tool->execute();

        self::assertInstanceOf(PermissionSummaryResult::class, $result);
        self::assertTrue($result->isAdmin);
        self::assertSame([], $result->tablesSelect);
    }

    public function testExecuteThrowsExceptionWhenNoUser(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('getPermissionSummary')
            ->willThrowException(new \RuntimeException('No authenticated backend user available', 1714000010));

        $tool = new PermissionCheckSummaryTool($permissionService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1714000010);

        $tool->execute();
    }
}
