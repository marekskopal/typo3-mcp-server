<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class DirectoryCreateTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'directory_create', description: 'Create a new directory in a storage.')]
    public function execute(string $directoryName, string $parentPath = '/', int $storageUid = 1): string
    {
        try {
            $result = $this->fileService->createDirectory($storageUid, $parentPath, $directoryName);
        } catch (\Throwable $e) {
            $this->logger->error('directory_create tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
