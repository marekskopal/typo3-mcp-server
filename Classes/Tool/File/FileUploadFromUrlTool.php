<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileUploadedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileUploadFromUrlTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'file_upload_from_url',
        description: 'Download a file from a URL and upload it to a storage directory. Useful for large files to avoid base64 encoding.'
            . ' Directory paths must lie inside the user\'s file mounts; call file_storage_list first if the valid roots are not already known.',
    )]
    public function execute(string $url, string $directoryPath = '/', int $storageUid = 1, string $fileName = '',): FileUploadedResult
    {
        $result = $this->fileService->uploadFileFromUrl($storageUid, $directoryPath, $url, $fileName);

        return new FileUploadedResult($result['uid'], $result['name'], $result['identifier'], $result['size'], $result['mimeType']);
    }
}
