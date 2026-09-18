<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\StorageListResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileStorageListTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'file_storage_list',
        description: 'List the file storages the current user may access, with the file mounts that bound them.'
            . ' Call this before any file or directory operation to learn which storageUid and directory paths are valid.'
            . ' Unless a storage reports fullAccess, the user is confined to the returned mount paths and their'
            . ' subfolders — build every directoryPath from a mount path (e.g. mount "/user_upload/" means'
            . ' "/user_upload/examples/", not "/examples/"). Paths outside the mounts are rejected, and mounts'
            . ' flagged readOnly reject writes.',
    )]
    public function execute(): StorageListResult
    {
        return new StorageListResult($this->fileService->listStorages()['storages']);
    }
}
