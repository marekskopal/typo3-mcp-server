<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileRenamedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileRenameTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(name: 'file_rename', description: 'Rename a file. Provide the file identifier and the new file name.')]
    public function execute(string $fileIdentifier, string $newName, int $storageUid = 1): FileRenamedResult
    {
        $this->fileService->renameFile($storageUid, $fileIdentifier, $newName);

        return new FileRenamedResult($fileIdentifier, $newName);
    }
}
