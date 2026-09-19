<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryCreatedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class DirectoryCreateTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'directory_create',
        description: 'Create a new directory in a storage.'
            . ' The parent path must lie inside the user\'s file mounts;'
            . ' call file_storage_list first if the valid roots are not already known.',
    )]
    public function execute(string $directoryName, string $parentPath = '/', int $storageUid = 1): DirectoryCreatedResult
    {
        $result = $this->fileService->createDirectory($storageUid, $parentPath, $directoryName);

        return new DirectoryCreatedResult($result['name'], $result['identifier']);
    }
}
