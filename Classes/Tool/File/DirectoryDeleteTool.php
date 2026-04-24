<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileDeletedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class DirectoryDeleteTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(name: 'directory_delete', description: 'Delete a directory from a storage.')]
    public function execute(string $directoryIdentifier, bool $recursive = false, int $storageUid = 1): FileDeletedResult
    {
        $this->fileService->deleteDirectory($storageUid, $directoryIdentifier, $recursive);

        return new FileDeletedResult($directoryIdentifier);
    }
}
