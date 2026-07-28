<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class FileListTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'file_list',
        description: 'List files and directories in a storage directory with pagination.'
            . ' For a user confined to file mounts, listing the root "/" returns the mount folders,'
            . ' which are the only paths their file operations may descend from.',
    )]
    public function execute(string $directoryPath = '/', int $storageUid = 1, int $limit = 20, int $offset = 0): string
    {
        $result = $this->fileService->listDirectory($storageUid, $directoryPath, $limit, $offset);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
