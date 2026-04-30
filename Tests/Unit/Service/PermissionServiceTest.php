<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[CoversClass(PermissionService::class)]
final class PermissionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    public function testCheckTableAccessReturnsSelectAndModifyForAllowedTable(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('check')->willReturnMap([
            ['tables_select', 'pages', true],
            ['tables_modify', 'pages', true],
        ]);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->checkTableAccess('pages');

        self::assertSame('pages', $result['table']);
        self::assertTrue($result['canSelect']);
        self::assertTrue($result['canModify']);
    }

    public function testCheckTableAccessReturnsFalseForDisallowedTable(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('check')->willReturn(false);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->checkTableAccess('sys_file');

        self::assertSame('sys_file', $result['table']);
        self::assertFalse($result['canSelect']);
        self::assertFalse($result['canModify']);
    }

    public function testCheckTableAccessReturnsSelectOnlyForReadOnlyTable(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('check')->willReturnMap([
            ['tables_select', 'tt_content', true],
            ['tables_modify', 'tt_content', false],
        ]);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->checkTableAccess('tt_content');

        self::assertTrue($result['canSelect']);
        self::assertFalse($result['canModify']);
    }

    public function testCheckPageAccessReturnsFullPermissionsForAdmin(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('calcPerms')->willReturn(Permission::ALL);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->checkPageAccess(['uid' => 1]);

        self::assertSame(1, $result['pageId']);
        self::assertTrue($result['canShow']);
        self::assertTrue($result['canEdit']);
        self::assertTrue($result['canDelete']);
        self::assertTrue($result['canCreateSubpages']);
        self::assertTrue($result['canEditContent']);
        self::assertSame(Permission::ALL, $result['permissionBitmask']);
    }

    public function testCheckPageAccessReturnsPartialPermissions(): void
    {
        $perms = Permission::PAGE_SHOW | Permission::CONTENT_EDIT;

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('calcPerms')->willReturn($perms);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->checkPageAccess(['uid' => 42]);

        self::assertSame(42, $result['pageId']);
        self::assertTrue($result['canShow']);
        self::assertFalse($result['canEdit']);
        self::assertFalse($result['canDelete']);
        self::assertFalse($result['canCreateSubpages']);
        self::assertTrue($result['canEditContent']);
        self::assertSame($perms, $result['permissionBitmask']);
    }

    public function testCheckPageAccessReturnsNothingForInaccessiblePage(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('calcPerms')->willReturn(Permission::NOTHING);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->checkPageAccess(['uid' => 99]);

        self::assertFalse($result['canShow']);
        self::assertFalse($result['canEdit']);
        self::assertFalse($result['canDelete']);
        self::assertFalse($result['canCreateSubpages']);
        self::assertFalse($result['canEditContent']);
        self::assertSame(Permission::NOTHING, $result['permissionBitmask']);
    }

    public function testGetPermissionSummaryReturnsAdminSummary(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->method('getFilePermissions')->willReturn([
            'addFile' => true,
            'readFile' => true,
            'writeFile' => true,
        ]);
        $backendUser->groupData = [
            'tables_select' => '',
            'tables_modify' => '',
            'allowed_languages' => '',
            'webmounts' => '',
            'filemounts' => '',
        ];

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->getPermissionSummary();

        self::assertTrue($result['isAdmin']);
        self::assertSame([], $result['tablesSelect']);
        self::assertSame([], $result['tablesModify']);
        self::assertSame([], $result['allowedLanguages']);
        self::assertSame(['addFile' => true, 'readFile' => true, 'writeFile' => true], $result['filePermissions']);
    }

    public function testGetPermissionSummaryReturnsEditorSummary(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getFilePermissions')->willReturn([
            'addFile' => false,
            'readFile' => true,
        ]);
        $backendUser->groupData = [
            'tables_select' => 'pages,tt_content,sys_file',
            'tables_modify' => 'pages,tt_content',
            'allowed_languages' => '0,1,2',
            'webmounts' => '1,5,10',
            'filemounts' => '3',
        ];

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->getPermissionSummary();

        self::assertFalse($result['isAdmin']);
        self::assertSame(['pages', 'tt_content', 'sys_file'], $result['tablesSelect']);
        self::assertSame(['pages', 'tt_content'], $result['tablesModify']);
        self::assertSame([0, 1, 2], $result['allowedLanguages']);
        self::assertSame([1, 5, 10], $result['webmounts']);
        self::assertSame([3], $result['filemounts']);
    }

    public function testGetPermissionSummaryHandlesWhitespaceInLists(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getFilePermissions')->willReturn([]);
        $backendUser->groupData = [
            'tables_select' => ' pages , tt_content ',
            'tables_modify' => '',
            'allowed_languages' => ' 0 , 1 ',
            'webmounts' => '',
            'filemounts' => '',
        ];

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();
        $result = $service->getPermissionSummary();

        self::assertSame(['pages', 'tt_content'], $result['tablesSelect']);
        self::assertSame([], $result['tablesModify']);
        self::assertSame([0, 1], $result['allowedLanguages']);
    }

    public function testCheckLanguageAccessDelegatesToBackendUser(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->expects(self::once())
            ->method('checkLanguageAccess')
            ->with(1)
            ->willReturn(true);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();

        self::assertTrue($service->checkLanguageAccess(1));
    }

    public function testCheckLanguageAccessReturnsFalseForDeniedLanguage(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('checkLanguageAccess')->with(5)->willReturn(false);

        $GLOBALS['BE_USER'] = $backendUser;

        $service = new PermissionService();

        self::assertFalse($service->checkLanguageAccess(5));
    }

    public function testThrowsExceptionWhenNoBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $service = new PermissionService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1714000010);

        $service->checkTableAccess('pages');
    }

    public function testIsAdminReturnsTrueForAdmin(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);

        $GLOBALS['BE_USER'] = $backendUser;

        self::assertTrue((new PermissionService())->isAdmin());
    }

    public function testIsAdminReturnsFalseForEditor(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);

        $GLOBALS['BE_USER'] = $backendUser;

        self::assertFalse((new PermissionService())->isAdmin());
    }
}
