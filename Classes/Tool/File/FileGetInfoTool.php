<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileInfoResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileGetInfoTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(name: 'file_get_info', description: 'Get metadata for a specific file by its identifier.')]
    public function execute(string $fileIdentifier, int $storageUid = 1): FileInfoResult
    {
        $result = $this->fileService->getFileInfo($storageUid, $fileIdentifier);

        return new FileInfoResult(
            $result['uid'],
            $result['name'],
            $result['identifier'],
            $result['size'],
            $result['mimeType'],
            $result['extension'],
            $result['modificationTime'],
            $result['publicUrl'],
        );
    }
}
