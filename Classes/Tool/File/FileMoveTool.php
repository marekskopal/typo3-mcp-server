<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileMovedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class FileMoveTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(
        name: 'file_move',
        description: 'Move a file to a different directory within the same storage.'
            . ' Provide the file identifier and the target directory path.',
    )]
    public function execute(string $fileIdentifier, string $targetDirectory, int $storageUid = 1): FileMovedResult
    {
        try {
            $this->fileService->moveFile($storageUid, $fileIdentifier, $targetDirectory);
        } catch (\Throwable $e) {
            $this->logger->error('file_move tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new FileMovedResult($fileIdentifier, $targetDirectory);
    }
}
