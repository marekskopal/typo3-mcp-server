<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class DirectoryDeleteTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'directory_delete', description: 'Delete a directory from a storage.')]
    public function execute(string $directoryIdentifier, bool $recursive = false, int $storageUid = 1): string
    {
        try {
            $this->fileService->deleteDirectory($storageUid, $directoryIdentifier, $recursive);
        } catch (\Throwable $e) {
            $this->logger->error('directory_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['identifier' => $directoryIdentifier, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
