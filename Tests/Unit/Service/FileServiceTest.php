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
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('test.txt');
        $file->method('getIdentifier')->willReturn('/test.txt');
        $file->method('getSize')->willReturn(1024);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getExtension')->willReturn('txt');
        $file->method('getModificationTime')->willReturn(1700000000);

        $subfolder = $this->createMock(Folder::class);
        $subfolder->method('getName')->willReturn('subdir');
        $subfolder->method('getIdentifier')->willReturn('/subdir/');
        $subfolder->method('getModificationTime')->willReturn(1700000000);

        $folder = $this->createMock(Folder::class);
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFolder')->with('/')->willReturn($folder);
        $storage->method('getFilesInFolder')->with($folder, 0, 20)->willReturn(['test.txt' => $file]);
        $storage->method('getFoldersInFolder')->with($folder, 0, 20)->willReturn(['subdir' => $subfolder]);
        $storage->method('countFilesInFolder')->with($folder)->willReturn(1);
        $storage->method('countFoldersInFolder')->with($folder)->willReturn(1);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

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
        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn(10);
        $file->method('getName')->willReturn('image.png');
        $file->method('getIdentifier')->willReturn('/images/image.png');
        $file->method('getSize')->willReturn(2048);
        $file->method('getMimeType')->willReturn('image/png');
        $file->method('getExtension')->willReturn('png');
        $file->method('getModificationTime')->willReturn(1700000000);
        $file->method('getPublicUrl')->willReturn('/fileadmin/images/image.png');

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->with('/images/image.png')->willReturn($file);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $service = new FileService($storageRepository);
        $result = $service->getFileInfo(1, '/images/image.png');

        self::assertSame('image.png', $result['name']);
        self::assertSame(2048, $result['size']);
        self::assertSame('image/png', $result['mimeType']);
        self::assertSame('/fileadmin/images/image.png', $result['publicUrl']);
    }

    public function testGetFileInfoThrowsWhenFileNotFound(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = new FileService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002001);

        $service->getFileInfo(1, '/nonexistent.txt');
    }

    public function testUploadFileCreatesFileWithContent(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn(42);
        $file->method('getName')->willReturn('upload.txt');
        $file->method('getIdentifier')->willReturn('/upload.txt');
        $file->method('getSize')->willReturn(13);
        $file->method('getMimeType')->willReturn('text/plain');

        $folder = $this->createMock(Folder::class);
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFolder')->with('/')->willReturn($folder);
        $storage->method('addFile')->willReturn($file);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $service = new FileService($storageRepository);
        $result = $service->uploadFile(1, '/', 'upload.txt', base64_encode('Hello, World!'));

        self::assertSame(42, $result['uid']);
        self::assertSame('upload.txt', $result['name']);
        self::assertSame(13, $result['size']);
    }

    public function testUploadFileThrowsOnInvalidBase64(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = new FileService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002002);

        $service->uploadFile(1, '/', 'test.txt', '!!!invalid-base64!!!');
    }

    public function testCreateDirectoryReturnsDirectoryInfo(): void
    {
        $parentFolder = $this->createMock(Folder::class);
        $newFolder = $this->createMock(Folder::class);
        $newFolder->method('getName')->willReturn('newdir');
        $newFolder->method('getIdentifier')->willReturn('/newdir/');

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFolder')->with('/')->willReturn($parentFolder);
        $storage->method('createFolder')->with('newdir', $parentFolder)->willReturn($newFolder);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $service = new FileService($storageRepository);
        $result = $service->createDirectory(1, '/', 'newdir');

        self::assertSame('newdir', $result['name']);
        self::assertSame('/newdir/', $result['identifier']);
    }

    public function testDeleteFileCallsStorageDeleteFile(): void
    {
        $file = $this->createMock(File::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->with('/test.txt')->willReturn($file);
        $storage->expects(self::once())->method('deleteFile')->with($file);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $service = new FileService($storageRepository);
        $service->deleteFile(1, '/test.txt');
    }

    public function testDeleteFileThrowsWhenFileNotFound(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFileByIdentifier')->willReturn(null);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturn($storage);

        $service = new FileService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002004);

        $service->deleteFile(1, '/nonexistent.txt');
    }

    public function testDeleteDirectoryCallsStorageDeleteFolder(): void
    {
        $folder = $this->createMock(Folder::class);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getFolder')->with('/old/')->willReturn($folder);
        $storage->expects(self::once())->method('deleteFolder')->with($folder, true);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $service = new FileService($storageRepository);
        $service->deleteDirectory(1, '/old/', true);
    }

    public function testGetStorageThrowsWhenNotFound(): void
    {
        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(999)->willReturn(null);

        $service = new FileService($storageRepository);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712002000);

        $service->listDirectory(999, '/', 20, 0);
    }
}
