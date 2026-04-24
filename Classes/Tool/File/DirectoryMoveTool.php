<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryMovedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class DirectoryMoveTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(
        name: 'directory_move',
        description: 'Move a directory to a different parent directory within the same storage.'
            . ' Provide the directory identifier and the target parent directory path.',
    )]
    public function execute(string $directoryIdentifier, string $targetDirectory, int $storageUid = 1): DirectoryMovedResult
    {
        try {
            $this->fileService->moveDirectory($storageUid, $directoryIdentifier, $targetDirectory);
        } catch (\Throwable $e) {
            $this->logger->error('directory_move tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new DirectoryMovedResult($directoryIdentifier, $targetDirectory);
    }
}
