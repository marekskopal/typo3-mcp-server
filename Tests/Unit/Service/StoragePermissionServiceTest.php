<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\StoragePermissionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceStorage;

#[CoversClass(StoragePermissionService::class)]
final class StoragePermissionServiceTest extends TestCase
{
    public function testAppliesFileMountsAndPermissionsForNonAdmin(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isFallbackStorage')->willReturn(false);
        $storage->method('getEvaluatePermissions')->willReturn(false);

        // Core's aspect never runs on the MCP path, so this is the call that actually turns the
        // filemount and file-permission guards in ResourceStorage on.
        $storage->expects(self::once())->method('setEvaluatePermissions')->with(true);
        $storage->expects(self::once())->method('setUserPermissions')->with([
            'addFile' => true,
            'deleteFile' => false,
        ]);
        $storage->expects(self::once())->method('addFileMount')->with('/user_upload/', self::anything());

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getFilePermissions')->willReturn(['addFile' => true, 'deleteFile' => false]);
        $backendUser->method('getTSConfig')->willReturn([]);
        $backendUser->method('getFileMountRecords')->willReturn([
            '1:/user_upload/' => ['identifier' => '1:/user_upload/', 'title' => 'User upload', 'read_only' => 0],
        ]);

        (new StoragePermissionService())->applyUserPermissions($storage, $backendUser);
    }

    public function testSkipsFileMountsOfOtherStorages(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(2);
        $storage->method('isFallbackStorage')->willReturn(false);
        $storage->method('getEvaluatePermissions')->willReturn(false);

        // Permission evaluation still goes on: a storage the user holds no mount in must end up
        // denying everything rather than staying wide open.
        $storage->expects(self::once())->method('setEvaluatePermissions')->with(true);
        $storage->expects(self::never())->method('addFileMount');

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getFilePermissions')->willReturn([]);
        $backendUser->method('getTSConfig')->willReturn([]);
        $backendUser->method('getFileMountRecords')->willReturn([
            '1:/user_upload/' => ['identifier' => '1:/user_upload/'],
            // A storage selection rather than a mount — no path component, so it is skipped.
            'broken' => ['identifier' => '3'],
        ]);

        (new StoragePermissionService())->applyUserPermissions($storage, $backendUser);
    }

    public function testOverlaysPerStorageTsConfigPermissions(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isFallbackStorage')->willReturn(false);
        $storage->method('getEvaluatePermissions')->willReturn(false);

        $storage->expects(self::once())->method('setUserPermissions')->with([
            'addFile' => true,
            'deleteFile' => false,
        ]);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getFilePermissions')->willReturn(['addFile' => true, 'deleteFile' => true]);
        $backendUser->method('getTSConfig')->willReturn([
            'permissions.' => ['file.' => ['storage.' => ['1.' => ['deleteFile' => '0']]]],
        ]);
        $backendUser->method('getFileMountRecords')->willReturn([]);

        (new StoragePermissionService())->applyUserPermissions($storage, $backendUser);
    }

    public function testStaleFileMountIsSkippedAndStorageStaysRestricted(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isFallbackStorage')->willReturn(false);
        $storage->method('getEvaluatePermissions')->willReturn(false);
        $storage->method('addFileMount')->willThrowException(new FolderDoesNotExistException('gone', 1334427099));

        // Fails closed: the mount is dropped but permission evaluation remains on.
        $storage->expects(self::once())->method('setEvaluatePermissions')->with(true);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('getFilePermissions')->willReturn([]);
        $backendUser->method('getTSConfig')->willReturn([]);
        $backendUser->method('getFileMountRecords')->willReturn([
            '1:/removed/' => ['identifier' => '1:/removed/'],
        ]);

        (new StoragePermissionService())->applyUserPermissions($storage, $backendUser);
    }

    public function testLeavesAdminStorageUnrestricted(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->expects(self::never())->method('setEvaluatePermissions');
        $storage->expects(self::never())->method('addFileMount');

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);

        (new StoragePermissionService())->applyUserPermissions($storage, $backendUser);
    }

    public function testLeavesFallbackStorageUnrestricted(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isFallbackStorage')->willReturn(true);
        $storage->expects(self::never())->method('setEvaluatePermissions');

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);

        (new StoragePermissionService())->applyUserPermissions($storage, $backendUser);
    }

    public function testDoesNotReapplyToAnAlreadyConfiguredStorage(): void
    {
        // Storage objects are cached per request, so the same instance reaches us repeatedly.
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isFallbackStorage')->willReturn(false);
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->expects(self::never())->method('setEvaluatePermissions');
        $storage->expects(self::never())->method('addFileMount');

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);

        (new StoragePermissionService())->applyUserPermissions($storage, $backendUser);
    }
}
