<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryRenamedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class DirectoryRenameTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(name: 'directory_rename', description: 'Rename a directory. Provide the directory identifier and the new name.')]
    public function execute(string $directoryIdentifier, string $newName, int $storageUid = 1): DirectoryRenamedResult
    {
        $this->fileService->renameDirectory($storageUid, $directoryIdentifier, $newName);

        return new DirectoryRenamedResult($directoryIdentifier, $newName);
    }
}
