<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class DirectoryCreateTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(name: 'directory_create', description: 'Create a new directory in a storage.')]
    public function execute(string $directoryName, string $parentPath = '/', int $storageUid = 1): string
    {
        $result = $this->fileService->createDirectory($storageUid, $parentPath, $directoryName);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
