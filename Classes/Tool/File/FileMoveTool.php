<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileMovedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileMoveTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'file_move',
        description: 'Move a file to a different directory within the same storage.'
            . ' Provide the file identifier and the target directory path.',
    )]
    public function execute(string $fileIdentifier, string $targetDirectory, int $storageUid = 1): FileMovedResult
    {
        $this->fileService->moveFile($storageUid, $fileIdentifier, $targetDirectory);

        return new FileMovedResult($fileIdentifier, $targetDirectory);
    }
}
