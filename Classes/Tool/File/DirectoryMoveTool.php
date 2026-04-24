<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryMovedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class DirectoryMoveTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'directory_move',
        description: 'Move a directory to a different parent directory within the same storage.'
            . ' Provide the directory identifier and the target parent directory path.',
    )]
    public function execute(string $directoryIdentifier, string $targetDirectory, int $storageUid = 1): DirectoryMovedResult
    {
        $this->fileService->moveDirectory($storageUid, $directoryIdentifier, $targetDirectory);

        return new DirectoryMovedResult($directoryIdentifier, $targetDirectory);
    }
}
