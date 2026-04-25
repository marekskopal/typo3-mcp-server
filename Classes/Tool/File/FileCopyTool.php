<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileCopiedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileCopyTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'file_copy',
        description: 'Copy a file to a directory within the same storage.'
            . ' Provide the file identifier and the target directory path.',
    )]
    public function execute(string $fileIdentifier, string $targetDirectory, int $storageUid = 1): FileCopiedResult
    {
        $this->fileService->copyFile($storageUid, $fileIdentifier, $targetDirectory);

        return new FileCopiedResult($fileIdentifier, $targetDirectory);
    }
}
