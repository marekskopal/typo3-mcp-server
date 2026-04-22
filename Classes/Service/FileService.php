<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use const PHP_URL_PATH;
use const PHP_URL_SCHEME;

readonly class FileService
{
    public function __construct(private StorageRepository $storageRepository)
    {
    }

    /** @return array{files: list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int}>, directories: list<array{name: string, identifier: string, modificationTime: int}>, totalFiles: int, totalDirectories: int} */
    public function listDirectory(int $storageUid, string $directoryPath, int $limit, int $offset): array
    {
        $limit = min(max($limit, 1), 500);

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

    /** @return array{uid: int, name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int, publicUrl: string|null} */
    public function getFileInfo(int $storageUid, string $fileIdentifier): array
    {
        $storage = $this->getStorage($storageUid);
        $file = $storage->getFileByIdentifier($fileIdentifier);

        if (!$file instanceof File) {
            throw new \RuntimeException('File not found: ' . $fileIdentifier, 1712002001);
        }

        return [
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'modificationTime' => $file->getModificationTime(),
            'publicUrl' => $file->getPublicUrl(),
        ];
    }

    /** @return array{uid: int, name: string, identifier: string, size: int, mimeType: string} */
    public function uploadFile(int $storageUid, string $directoryPath, string $fileName, string $content): array
    {
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
            'uid' => $file->getUid(),
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'size' => $file->getSize(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    /** @return array{uid: int, name: string, identifier: string, size: int, mimeType: string} */
    public function uploadFileFromUrl(int $storageUid, string $directoryPath, string $url, string $fileName = ''): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http and https URLs are allowed', 1712002010);
        }

        if ($fileName === '') {
            $path = parse_url($url, PHP_URL_PATH);
            $fileName = is_string($path) ? basename($path) : '';
            if ($fileName === '' || $fileName === '.') {
                $fileName = 'download_' . bin2hex(random_bytes(4));
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'max_redirects' => 5,
                'follow_location' => 1,
                'method' => 'GET',
                'user_agent' => 'TYPO3-MCP-Server/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if ($content === false) {
            throw new \RuntimeException('Failed to download file from URL: ' . $url, 1712002011);
        }

        // 100 MB
        $maxSize = 104857600;
        if (strlen($content) > $maxSize) {
            throw new \RuntimeException('Downloaded file exceeds maximum allowed size of 100 MB', 1712002012);
        }

        $storage = $this->getStorage($storageUid);
        $folder = $storage->getFolder($directoryPath);

        $tempFile = tempnam(sys_get_temp_dir(), 'mcp_url_upload_');
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
            'uid' => $file->getUid(),
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
