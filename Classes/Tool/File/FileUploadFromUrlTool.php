<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class FileUploadFromUrlTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'file_upload_from_url',
        description: 'Download a file from a URL and upload it to a storage directory. Useful for large files to avoid base64 encoding.',
    )]
    public function execute(string $url, string $directoryPath = '/', int $storageUid = 1, string $fileName = '',): string
    {
        $result = $this->fileService->uploadFileFromUrl($storageUid, $directoryPath, $url, $fileName);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
