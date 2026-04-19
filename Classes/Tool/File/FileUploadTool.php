<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class FileUploadTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'file_upload', description: 'Upload a file to a storage directory. Content must be base64-encoded.')]
    public function execute(string $fileName, string $base64Content, string $directoryPath = '/', int $storageUid = 1,): string
    {
        try {
            $result = $this->fileService->uploadFile($storageUid, $directoryPath, $fileName, $base64Content);
        } catch (\Throwable $e) {
            $this->logger->error('file_upload tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
