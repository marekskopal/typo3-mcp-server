<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileDeletedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class DirectoryDeleteTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'directory_delete', description: 'Delete a directory from a storage.')]
    public function execute(string $directoryIdentifier, bool $recursive = false, int $storageUid = 1): FileDeletedResult
    {
        try {
            $this->fileService->deleteDirectory($storageUid, $directoryIdentifier, $recursive);
        } catch (\Throwable $e) {
            $this->logger->error('directory_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new FileDeletedResult($directoryIdentifier);
    }
}
