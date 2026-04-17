<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

readonly class FileService
{
    public function __construct(private StorageRepository $storageRepository)
    {
    }

    /** @return array{files: list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int}>, directories: list<array{name: string, identifier: string, modificationTime: int}>, totalFiles: int, totalDirectories: int} */
    public function listDirectory(int $storageUid, string $directoryPath, int $limit, int $offset): array
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryPath);

        $totalFiles = $storage->countFilesInFolder($folder);
        $totalDirectories = $storage->countFoldersInFolder($folder);

        $files = [];
        foreach ($storage->getFilesInFolder($folder, $offset, $limit) as $file) {
            $files[] = $this->mapFileToArray($file);
        }

        $directories = [];
        foreach ($storage->getFoldersInFolder($folder, $offset, $limit) as $subfolder) {
            $directories[] = $this->mapFolderToArray($subfolder);
        }

        return [
            'files' => $files,
            'directories' => $directories,
            'totalFiles' => $totalFiles,
            'totalDirectories' => $totalDirectories,
        ];
    }

    /** @return array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int, publicUrl: string|null} */
    public function getFileInfo(int $storageUid, string $fileIdentifier): array
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002001);
        }

        return [
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
            'publicUrl' => $file->getPublicUrl(),
        ];
    }

    /** @return array{name: string, identifier: string, size: int, mimeType: string} */
    public function uploadFile(int $storageUid, string $directoryPath, string $fileName, string $base64Content): array
    {
        $content = base64_decode($base64Content, true);
        if ($content === false) {
            throw new \RuntimeException('Invalid base64 content', 1712002002);
        }

        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryPath);

        $tempFile = tempnam(sys_get_temp_dir(), 'mcp_upload_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file', 1712002003);
        }

        try {
            file_put_contents($tempFile, $content);
            $file = $storage->addFile($tempFile, $folder, $fileName);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return [
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    /** @return array{name: string, identifier: string} */
    public function createDirectory(int $storageUid, string $parentPath, string $directoryName): array
    {
        $storage = $this->getStorage($storageUid);
        $parentFolder = $storage->getFolder($parentPath);
        $folder = $storage->createFolder($directoryName, $parentFolder);

        return [
            'name' => $folder->getName(),
            'identifier' => $folder->getIdentifier(),
        ];
    }

    public function deleteFile(int $storageUid, string $fileIdentifier): void
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002004);
        }

        $storage->deleteFile($file);
    }

    public function deleteDirectory(int $storageUid, string $directoryIdentifier, bool $recursive): void
    {
        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryIdentifier);
        $storage->deleteFolder($folder, $recursive);
    }

    private function getStorage(int $storageUid): ResourceStorage
    {
        $storage = $this->storageRepository->findByUid($storageUid);

        if ($storage === null) {
            throw new \RuntimeException('Storage not found: ' . $storageUid, 1712002000);
        }

        return $storage;
    }

    /** @return array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int} */
    private function mapFileToArray(File $file): array
    {
        return [
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
        ];
    }

    /** @return array{name: string, identifier: string, modificationTime: int} */
    private function mapFolderToArray(Folder $folder): array
    {
        return [
            'name' => $folder->getName(),
            'identifier' => $folder->getIdentifier(),
            'modificationTime' => $folder->getModificationTime(),
        ];
    }
}
