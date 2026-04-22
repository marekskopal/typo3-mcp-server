<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileDeletedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class FileDeleteTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'file_delete', description: 'Delete a file by its identifier from a storage.')]
    public function execute(string $fileIdentifier, int $storageUid = 1): FileDeletedResult
    {
        try {
            $this->fileService->deleteFile($storageUid, $fileIdentifier);
        } catch (\Throwable $e) {
            $this->logger->error('file_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new FileDeletedResult($fileIdentifier);
    }
}
