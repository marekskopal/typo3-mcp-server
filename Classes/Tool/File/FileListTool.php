<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class FileListTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'file_list', description: 'List files and directories in a storage directory with pagination.')]
    public function execute(string $directoryPath = '/', int $storageUid = 1, int $limit = 20, int $offset = 0): string
    {
        try {
            $result = $this->fileService->listDirectory($storageUid, $directoryPath, $limit, $offset);
        } catch (\Throwable $e) {
            $this->logger->error('file_list tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
