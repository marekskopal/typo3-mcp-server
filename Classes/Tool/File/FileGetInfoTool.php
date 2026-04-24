<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class FileGetInfoTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(name: 'file_get_info', description: 'Get metadata for a specific file by its identifier.')]
    public function execute(string $fileIdentifier, int $storageUid = 1): string
    {
        $result = $this->fileService->getFileInfo($storageUid, $fileIdentifier);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
