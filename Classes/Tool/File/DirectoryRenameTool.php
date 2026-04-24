<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryRenamedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class DirectoryRenameTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'directory_rename', description: 'Rename a directory. Provide the directory identifier and the new name.')]
    public function execute(string $directoryIdentifier, string $newName, int $storageUid = 1): DirectoryRenamedResult
    {
        try {
            $this->fileService->renameDirectory($storageUid, $directoryIdentifier, $newName);
        } catch (\Throwable $e) {
            $this->logger->error('directory_rename tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new DirectoryRenamedResult($directoryIdentifier, $newName);
    }
}
