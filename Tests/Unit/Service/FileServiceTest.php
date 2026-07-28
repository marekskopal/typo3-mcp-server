<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Service\StoragePermissionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Result;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[CoversClass(FileService::class)]
final class FileServiceTest extends TestCase
{
    public function testResolvesStorageThroughBackendUserAccessibleStorages(): void
    {
        $folder = $this->createStub(Folder::class);

        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('getFilesInFolder')->willReturn([]);
        $storage->method('getFoldersInFolder')->willReturn([]);
        $storage->method('countFilesInFolder')->willReturn(0);
        $storage->method('countFoldersInFolder')->willReturn(0);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            // StorageRepository must NOT be consulted when a backend user is present.
            $storageRepository = $this->createMock(StorageRepository::class);
            $storageRepository->expects(self::never())->method('findByUid');

            $service = $this->createService($storageRepository);
            $result = $service->listDirectory(7, '/', 20, 0);

            self::assertSame(0, $result['totalFiles']);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testThrowsWhenStorageNotAccessibleToBackendUser(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $service = $this->createService();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Storage not found or not accessible: 7');

            $service->listDirectory(7, '/', 20, 0);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testListDirectoryReturnsFilesAndDirectories(): void
    {
        $file = $this->createStub(File::class);
        $file->method('getName')->willReturn('test.txt');
        $file->method('getIdentifier')->willReturn('/test.txt');
        $file->method('getSize')->willReturn(1024);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getExtension')->willReturn('txt');
        $file->method('getModificationTime')->willReturn(1700000000);

        $subfolder = $this->createStub(Folder::class);
        $subfolder->method('getName')->willReturn('subdir');
        $subfolder->method('getIdentifier')->willReturn('/subdir/');
        $subfolder->method('getModificationTime')->willReturn(1700000000);

        $folder = $this->createStub(Folder::class);
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('getFilesInFolder')->willReturn(['test.txt' => $file]);
        $storage->method('getFoldersInFolder')->willReturn(['subdir' => $subfolder]);
        $storage->method('countFilesInFolder')->willReturn(1);
        $storage->method('countFoldersInFolder')->willReturn(1);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $result = $service->listDirectory(1, '/', 20, 0);

        self::assertCount(1, $result['files']);
        self::assertSame('test.txt', $result['files'][0]['name']);
        self::assertSame(1024, $result['files'][0]['size']);
        self::assertCount(1, $result['directories']);
        self::assertSame('subdir', $result['directories'][0]['name']);
        self::assertSame(1, $result['totalFiles']);
        self::assertSame(1, $result['totalDirectories']);
    }

    public function testGetFileInfoReturnsFileMetadata(): void
    {
        $file = $this->createStub(File::class);
        $file->method('getUid')->willReturn(10);
        $file->method('getName')->willReturn('image.png');
        $file->method('getIdentifier')->willReturn('/images/image.png');
        $file->method('getSize')->willReturn(2048);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getExtension')->willReturn('png');
        $file->method('getModificationTime')->willReturn(1700000000);
        $file->method('getPublicUrl')->willReturn('/fileadmin/images/image.png');

        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn($file);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $result = $service->getFileInfo(1, '/images/image.png');

        self::assertSame('image.png', $result['name']);
        self::assertSame(2048, $result['size']);
        self::assertSame('image/png', $result['mimeType']);
        self::assertSame('/fileadmin/images/image.png', $result['publicUrl']);
    }

    public function testGetFileInfoThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002001);

        $service->getFileInfo(1, '/nonexistent.txt');
    }

    public function testUploadFileCreatesFileWithContent(): void
    {
        $file = $this->createStub(File::class);
        $file->method('getUid')->willReturn(42);
        $file->method('getName')->willReturn('upload.txt');
        $file->method('getIdentifier')->willReturn('/upload.txt');
        $file->method('getSize')->willReturn(13);
        $file->method('getMimeType')->willReturn('text/plain');

        $folder = $this->createStub(Folder::class);
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('addFile')->willReturn($file);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $result = $service->uploadFile(1, '/', 'upload.txt', 'Hello, World!');

        self::assertSame(42, $result['uid']);
        self::assertSame('upload.txt', $result['name']);
        self::assertSame(13, $result['size']);
    }

    public function testCreateDirectoryReturnsDirectoryInfo(): void
    {
        $parentFolder = $this->createStub(Folder::class);
        $newFolder = $this->createStub(Folder::class);
        $newFolder->method('getName')->willReturn('newdir');
        $newFolder->method('getIdentifier')->willReturn('/newdir/');

        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFolder')->willReturn($parentFolder);
        $storage->method('createFolder')->willReturn($newFolder);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $result = $service->createDirectory(1, '/', 'newdir');

        self::assertSame('newdir', $result['name']);
        self::assertSame('/newdir/', $result['identifier']);
    }

    public function testCopyFileCallsStorageCopyFile(): void
    {
        $file = $this->createStub(File::class);
        $targetFolder = $this->createStub(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->with('/test.txt')->willReturn($file);
        $storage->method('getFolder')->with('/target/')->willReturn($targetFolder);
        $storage->expects(self::once())->method('copyFile')->with($file, $targetFolder);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $service->copyFile(1, '/test.txt', '/target/');
    }

    public function testCopyFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002007);

        $service->copyFile(1, '/nonexistent.txt', '/target/');
    }

    public function testMoveFileCallsStorageMoveFile(): void
    {
        $file = $this->createStub(File::class);
        $targetFolder = $this->createStub(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->with('/test.txt')->willReturn($file);
        $storage->method('getFolder')->with('/target/')->willReturn($targetFolder);
        $storage->expects(self::once())->method('moveFile')->with($file, $targetFolder);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $service->moveFile(1, '/test.txt', '/target/');
    }

    public function testMoveFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002005);

        $service->moveFile(1, '/nonexistent.txt', '/target/');
    }

    public function testRenameFileCallsStorageRenameFile(): void
    {
        $file = $this->createStub(File::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->with('/test.txt')->willReturn($file);
        $storage->expects(self::once())->method('renameFile')->with($file, 'new-name.txt');

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $service->renameFile(1, '/test.txt', 'new-name.txt');
    }

    public function testRenameFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002006);

        $service->renameFile(1, '/nonexistent.txt', 'new-name.txt');
    }

    public function testDeleteFileCallsStorageDeleteFile(): void
    {
        $file = $this->createStub(File::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->with('/test.txt')->willReturn($file);
        $storage->expects(self::once())->method('deleteFile')->with($file);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $service->deleteFile(1, '/test.txt');
    }

    public function testDeleteFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002004);

        $service->deleteFile(1, '/nonexistent.txt');
    }

    public function testMoveDirectoryCallsStorageMoveFolder(): void
    {
        $folder = $this->createStub(Folder::class);
        $targetFolder = $this->createStub(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFolder')->willReturnCallback(
            fn (string $path): Folder => $path === '/source/' ? $folder : $targetFolder,
        );
        $storage->expects(self::once())->method('moveFolder')->with($folder, $targetFolder);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $service->moveDirectory(1, '/source/', '/target/');
    }

    public function testRenameDirectoryCallsStorageRenameFolder(): void
    {
        $folder = $this->createStub(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFolder')->with('/old-name/')->willReturn($folder);
        $storage->expects(self::once())->method('renameFolder')->with($folder, 'new-name');

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $service->renameDirectory(1, '/old-name/', 'new-name');
    }

    public function testDeleteDirectoryCallsStorageDeleteFolder(): void
    {
        $folder = $this->createStub(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFolder')->with('/old/')->willReturn($folder);
        $storage->expects(self::once())->method('deleteFolder')->with($folder, true);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = $this->createService($storageRepository);
        $service->deleteDirectory(1, '/old/', true);
    }

    public function testUploadFileFromUrlRejectsNonHttpScheme(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002010);

        $service->uploadFileFromUrl(1, '/', 'ftp://example.com/file.txt');
    }

    public function testUploadFileFromUrlRejectsInvalidUrl(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002010);

        $service->uploadFileFromUrl(1, '/', 'not-a-url');
    }

    public function testUploadFileFromUrlRejectsHostResolvingToPrivateIp(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002015);

        // Loopback literal — gethostbyname returns 127.0.0.1, which fails the private/reserved
        // IP filter. This also exercises the path that would catch a DNS rebind to localhost.
        $service->uploadFileFromUrl(1, '/', 'http://127.0.0.1/file.txt');
    }

    public function testUploadFileFromUrlRejectsLinkLocalMetadataIp(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002015);

        // AWS / GCP / Azure cloud metadata endpoint — must be blocked.
        $service->uploadFileFromUrl(1, '/', 'http://169.254.169.254/latest/meta-data/');
    }

    public function testGetStorageThrowsWhenNotFound(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn(null);

        $service = $this->createService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002000);

        $service->listDirectory(999, '/', 20, 0);
    }

    public function testSearchFilesThrowsWhenStorageNotAccessibleToBackendUser(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $service = $this->createService();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Storage not found or not accessible: 7');

            $service->searchFiles(7, 'foo', '', 20, 0);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testSearchFilesQueriesWhenStorageAccessible(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $dbResult = $this->createStub(Result::class);
            $dbResult->method('fetchOne')->willReturn(0);
            $dbResult->method('fetchAllAssociative')->willReturn([]);

            $queryBuilder = $this->createStub(QueryBuilder::class);
            $queryBuilder->method('select')->willReturnSelf();
            $queryBuilder->method('count')->willReturnSelf();
            $queryBuilder->method('from')->willReturnSelf();
            $queryBuilder->method('andWhere')->willReturnSelf();
            $queryBuilder->method('setMaxResults')->willReturnSelf();
            $queryBuilder->method('setFirstResult')->willReturnSelf();
            $queryBuilder->method('orderBy')->willReturnSelf();
            $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
            $queryBuilder->method('createNamedParameter')->willReturn("'x'");
            $queryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));
            $queryBuilder->method('executeQuery')->willReturn($dbResult);

            $connectionPool = $this->createStub(ConnectionPool::class);
            $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

            $service = $this->createService(null, $connectionPool);
            $result = $service->searchFiles(7, 'foo', '', 20, 0);

            self::assertSame([], $result['files']);
            self::assertSame(0, $result['total']);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testSearchFilesReturnsEmptyWhenPermissionsEvaluatedAndNoFileMounts(): void
    {
        // A non-admin user without any filemount in the storage may reach nothing in it,
        // so the search must return empty instead of enumerating the whole storage.
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->method('getFileMounts')->willReturn([]);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $queryBuilder = $this->createStub(QueryBuilder::class);
            $queryBuilder->method('select')->willReturnSelf();
            $queryBuilder->method('count')->willReturnSelf();
            $queryBuilder->method('from')->willReturnSelf();
            $queryBuilder->method('andWhere')->willReturnSelf();
            $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
            $queryBuilder->method('createNamedParameter')->willReturn("'x'");
            $queryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));

            $connectionPool = $this->createStub(ConnectionPool::class);
            $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

            $service = $this->createService(null, $connectionPool);
            $result = $service->searchFiles(7, '', '', 20, 0);

            self::assertSame([], $result['files']);
            self::assertSame(0, $result['total']);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testSearchFilesConstrainsResultsToFileMounts(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->method('getFileMounts')->willReturn(['/user_upload/' => ['title' => 'User upload']]);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $likeConditions = [];

            $expressionBuilder = $this->createStub(ExpressionBuilder::class);
            $expressionBuilder->method('like')
                ->willReturnCallback(static function (string $field, string $value) use (&$likeConditions): string {
                    $likeConditions[] = [$field, $value];

                    return $field . ' LIKE ' . $value;
                });

            $dbResult = $this->createStub(Result::class);
            $dbResult->method('fetchOne')->willReturn(0);
            $dbResult->method('fetchAllAssociative')->willReturn([]);

            $queryBuilder = $this->createStub(QueryBuilder::class);
            $queryBuilder->method('select')->willReturnSelf();
            $queryBuilder->method('count')->willReturnSelf();
            $queryBuilder->method('from')->willReturnSelf();
            $queryBuilder->method('andWhere')->willReturnSelf();
            $queryBuilder->method('setMaxResults')->willReturnSelf();
            $queryBuilder->method('setFirstResult')->willReturnSelf();
            $queryBuilder->method('orderBy')->willReturnSelf();
            $queryBuilder->method('expr')->willReturn($expressionBuilder);
            $queryBuilder->method('createNamedParameter')
                ->willReturnCallback(static fn(mixed $value): string => "'" . $value . "'");
            $queryBuilder->method('escapeLikeWildcards')
                ->willReturnCallback(static fn(string $value): string => $value);
            $queryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));
            $queryBuilder->method('executeQuery')->willReturn($dbResult);

            $connectionPool = $this->createStub(ConnectionPool::class);
            $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

            $service = $this->createService(null, $connectionPool);
            $service->searchFiles(7, '', '', 20, 0);

            // Both the list and the count query must be confined to the filemount path.
            self::assertContains(['identifier', "'/user_upload/%'"], $likeConditions);
            self::assertCount(2, array_filter(
                $likeConditions,
                static fn(array $condition): bool => $condition[0] === 'identifier',
            ));
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testListStoragesReportsAccessibleMounts(): void
    {
        $mountFolder = $this->createStub(Folder::class);
        $mountFolder->method('getIdentifier')->willReturn('/user_upload/');

        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getName')->willReturn('fileadmin');
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->method('getFileMounts')->willReturn([
            '/user_upload/' => ['folder' => $mountFolder, 'title' => 'User upload', 'read_only' => 1],
        ]);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $result = $this->createService()->listStorages();

            self::assertSame([
                'storages' => [
                    [
                        'uid' => 7,
                        'name' => 'fileadmin',
                        'fullAccess' => false,
                        'mounts' => [['path' => '/user_upload/', 'title' => 'User upload', 'readOnly' => true]],
                    ],
                ],
            ], $result);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testListStoragesMarksUnrestrictedStorageAsFullAccess(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getName')->willReturn('fileadmin');
        $storage->method('getEvaluatePermissions')->willReturn(false);
        $storage->method('getFileMounts')->willReturn([]);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([1 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $result = $this->createService()->listStorages();

            self::assertTrue($result['storages'][0]['fullAccess']);
            self::assertSame([], $result['storages'][0]['mounts']);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testListStoragesReturnsEmptyWithoutBackendUser(): void
    {
        self::assertSame(['storages' => []], $this->createService()->listStorages());
    }

    public function testListDirectoryReturnsMountFoldersForRestrictedStorageRoot(): void
    {
        // The storage root lies outside the user's mounts, so getFolder('/') would only throw.
        // Listing the mounts instead is what lets the client discover where it may work.
        $mountFolder = $this->createStub(Folder::class);
        $mountFolder->method('getName')->willReturn('user_upload');
        $mountFolder->method('getIdentifier')->willReturn('/user_upload/');
        $mountFolder->method('getModificationTime')->willReturn(1700000000);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->method('getFileMounts')->willReturn(['/user_upload/' => ['folder' => $mountFolder]]);
        $storage->expects(self::never())->method('getFolder');

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $result = $this->createService()->listDirectory(7, '/', 20, 0);

            self::assertSame([], $result['files']);
            self::assertSame(0, $result['totalFiles']);
            self::assertSame(1, $result['totalDirectories']);
            self::assertSame('/user_upload/', $result['directories'][0]['identifier']);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testListDirectoryReadsNormallyBelowAMountRoot(): void
    {
        // Only the root gets the mount listing; a real path underneath goes through the storage,
        // where core rejects anything outside the mounts.
        $mountFolder = $this->createStub(Folder::class);
        $mountFolder->method('getIdentifier')->willReturn('/user_upload/');

        $folder = $this->createStub(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->method('getFileMounts')->willReturn(['/user_upload/' => ['folder' => $mountFolder]]);
        $storage->method('getFilesInFolder')->willReturn([]);
        $storage->method('getFoldersInFolder')->willReturn([]);
        $storage->method('countFilesInFolder')->willReturn(0);
        $storage->method('countFoldersInFolder')->willReturn(0);
        $storage->expects(self::once())->method('getFolder')->with('/user_upload/')->willReturn($folder);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $this->createService()->listDirectory(7, '/user_upload/', 20, 0);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testListDirectoryReadsRootNormallyWhenRootItselfIsMounted(): void
    {
        $mountFolder = $this->createStub(Folder::class);
        $mountFolder->method('getIdentifier')->willReturn('/');

        $folder = $this->createStub(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->method('getFileMounts')->willReturn(['/' => ['folder' => $mountFolder]]);
        $storage->method('getFilesInFolder')->willReturn([]);
        $storage->method('getFoldersInFolder')->willReturn([]);
        $storage->method('countFilesInFolder')->willReturn(0);
        $storage->method('countFoldersInFolder')->willReturn(0);
        $storage->expects(self::once())->method('getFolder')->with('/')->willReturn($folder);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $this->createService()->listDirectory(7, '/', 20, 0);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testListDirectorySurfacesPermissionErrorWhenRestrictedStorageHasNoMounts(): void
    {
        // No mount at all: fall through to the storage so the caller gets core's permission
        // error rather than a misleading empty listing.
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);
        $storage->method('getFileMounts')->willReturn([]);
        $storage->expects(self::once())
            ->method('getFolder')
            ->willThrowException(new \RuntimeException('no access', 1323059807));

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('no access');

            $this->createService()->listDirectory(7, '/', 20, 0);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testGetStorageAppliesUserPermissionsToTheResolvedStorage(): void
    {
        $folder = $this->createStub(Folder::class);

        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFolder')->willReturn($folder);
        $storage->method('getFilesInFolder')->willReturn([]);
        $storage->method('getFoldersInFolder')->willReturn([]);
        $storage->method('countFilesInFolder')->willReturn(0);
        $storage->method('countFoldersInFolder')->willReturn(0);

        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getFileStorages')->willReturn([7 => $storage]);
        $GLOBALS['BE_USER'] = $backendUser;

        try {
            $storagePermissionService = $this->createMock(StoragePermissionService::class);
            $storagePermissionService->expects(self::once())
                ->method('applyUserPermissions')
                ->with($storage, $backendUser);

            $service = new FileService(
                $this->createStub(StorageRepository::class),
                $this->createStub(ConnectionPool::class),
                $storagePermissionService,
            );
            $service->listDirectory(7, '/', 20, 0);
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    private function createService(
        ?StorageRepository $storageRepository = null,
        ?ConnectionPool $connectionPool = null,
    ): FileService {
        return new FileService(
            $storageRepository ?? $this->createStub(StorageRepository::class),
            $connectionPool ?? $this->createStub(ConnectionPool::class),
            // Applying the user's mounts/permissions to a storage is covered by
            // StoragePermissionServiceTest. Stub it here so each case can configure its storage
            // stub explicitly instead of having those calls overwritten.
            $this->createStub(StoragePermissionService::class),
        );
    }
}
