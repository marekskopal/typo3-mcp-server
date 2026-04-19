<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class FileDeleteTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'file_delete', description: 'Delete a file by its identifier from a storage.')]
    public function execute(string $fileIdentifier, int $storageUid = 1): string
    {
        try {
            $this->fileService->deleteFile($storageUid, $fileIdentifier);
        } catch (\Throwable $e) {
            $this->logger->error('file_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['identifier' => $fileIdentifier, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
