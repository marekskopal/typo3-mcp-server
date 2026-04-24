<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\FileService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[CoversClass(FileService::class)]
final class FileServiceTest extends TestCase
{
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

        $service = new FileService($storageRepository);
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

        $service = new FileService($storageRepository);
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

        $service = new FileService($storageRepository);

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

        $service = new FileService($storageRepository);
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

        $service = new FileService($storageRepository);
        $result = $service->createDirectory(1, '/', 'newdir');

        self::assertSame('newdir', $result['name']);
        self::assertSame('/newdir/', $result['identifier']);
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

        $service = new FileService($storageRepository);
        $service->moveFile(1, '/test.txt', '/target/');
    }

    public function testMoveFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = new FileService($storageRepository);

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

        $service = new FileService($storageRepository);
        $service->renameFile(1, '/test.txt', 'new-name.txt');
    }

    public function testRenameFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = new FileService($storageRepository);

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

        $service = new FileService($storageRepository);
        $service->deleteFile(1, '/test.txt');
    }

    public function testDeleteFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createStub(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = new FileService($storageRepository);

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

        $service = new FileService($storageRepository);
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

        $service = new FileService($storageRepository);
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

        $service = new FileService($storageRepository);
        $service->deleteDirectory(1, '/old/', true);
    }

    public function testUploadFileFromUrlRejectsNonHttpScheme(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $service = new FileService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002010);

        $service->uploadFileFromUrl(1, '/', 'ftp://example.com/file.txt');
    }

    public function testUploadFileFromUrlRejectsInvalidUrl(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $service = new FileService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002010);

        $service->uploadFileFromUrl(1, '/', 'not-a-url');
    }

    public function testGetStorageThrowsWhenNotFound(): void
    {
        $storageRepository = $this->createStub(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn(null);

        $service = new FileService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002000);

        $service->listDirectory(999, '/', 20, 0);
    }
}
