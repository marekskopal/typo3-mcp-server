<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class FileGetInfoTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'file_get_info', description: 'Get metadata for a specific file by its identifier.')]
    public function execute(string $fileIdentifier, int $storageUid = 1): string
    {
        try {
            $result = $this->fileService->getFileInfo($storageUid, $fileIdentifier);
        } catch (\Throwable $e) {
            $this->logger->error('file_get_info tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
