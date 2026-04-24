<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileDeletedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileDeleteTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(name: 'file_delete', description: 'Delete a file by its identifier from a storage.')]
    public function execute(string $fileIdentifier, int $storageUid = 1): FileDeletedResult
    {
        $this->fileService->deleteFile($storageUid, $fileIdentifier);

        return new FileDeletedResult($fileIdentifier);
    }
}
