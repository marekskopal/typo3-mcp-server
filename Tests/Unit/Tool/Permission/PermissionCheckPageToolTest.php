<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Permission;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Permission\PermissionCheckPageTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\PagePermissionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[CoversClass(PermissionCheckPageTool::class)]
final class PermissionCheckPageToolTest extends TestCase
{
    public function testExecuteReturnsPagePermissions(): void
    {
        $pageRow = [
            'uid' => 1,
            'pid' => 0,
            'perms_userid' => 1,
            'perms_user' => Permission::ALL,
            'perms_groupid' => 0,
            'perms_group' => Permission::NOTHING,
            'perms_everybody' => Permission::NOTHING,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('pages', 1, ['uid', 'pid', 'perms_userid', 'perms_user', 'perms_groupid', 'perms_group', 'perms_everybody'])
            ->willReturn($pageRow);

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->expects(self::once())
            ->method('checkPageAccess')
            ->with($pageRow)
            ->willReturn([
                'pageId' => 1,
                'canShow' => true,
                'canEdit' => true,
                'canDelete' => true,
                'canCreateSubpages' => true,
                'canEditContent' => true,
                'permissionBitmask' => Permission::ALL,
            ]);

        $tool = new PermissionCheckPageTool($permissionService, $recordService);
        $result = $tool->execute(1);

        self::assertInstanceOf(PagePermissionResult::class, $result);
        self::assertSame(1, $result->pageId);
        self::assertTrue($result->canShow);
        self::assertTrue($result->canEdit);
        self::assertTrue($result->canDelete);
        self::assertTrue($result->canCreateSubpages);
        self::assertTrue($result->canEditContent);
        self::assertSame(Permission::ALL, $result->permissionBitmask);
    }

    public function testExecuteReturnsErrorWhenPageNotFound(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $permissionService = $this->createStub(PermissionService::class);

        $tool = new PermissionCheckPageTool($permissionService, $recordService);
        $result = $tool->execute(999);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('Page not found', $result->error);
        self::assertSame(999, $result->context['pageId']);
    }

    public function testExecuteReturnsPartialPermissions(): void
    {
        $pageRow = [
            'uid' => 42,
            'pid' => 1,
            'perms_userid' => 2,
            'perms_user' => Permission::PAGE_SHOW | Permission::CONTENT_EDIT,
            'perms_groupid' => 0,
            'perms_group' => Permission::NOTHING,
            'perms_everybody' => Permission::NOTHING,
        ];

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn($pageRow);

        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('checkPageAccess')->willReturn([
            'pageId' => 42,
            'canShow' => true,
            'canEdit' => false,
            'canDelete' => false,
            'canCreateSubpages' => false,
            'canEditContent' => true,
            'permissionBitmask' => Permission::PAGE_SHOW | Permission::CONTENT_EDIT,
        ]);

        $tool = new PermissionCheckPageTool($permissionService, $recordService);
        $result = $tool->execute(42);

        self::assertInstanceOf(PagePermissionResult::class, $result);
        self::assertTrue($result->canShow);
        self::assertFalse($result->canEdit);
        self::assertFalse($result->canDelete);
        self::assertFalse($result->canCreateSubpages);
        self::assertTrue($result->canEditContent);
    }

    public function testExecuteThrowsExceptionOnServiceError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 1]);

        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('checkPageAccess')
            ->willThrowException(new \RuntimeException('No authenticated backend user available', 1714000010));

        $tool = new PermissionCheckPageTool($permissionService, $recordService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1714000010);

        $tool->execute(1);
    }
}
