<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileRenamedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class FileRenameTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(name: 'file_rename', description: 'Rename a file. Provide the file identifier and the new file name.')]
    public function execute(string $fileIdentifier, string $newName, int $storageUid = 1): FileRenamedResult
    {
        try {
            $this->fileService->renameFile($storageUid, $fileIdentifier, $newName);
        } catch (\Throwable $e) {
            $this->logger->error('file_rename tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new FileRenamedResult($fileIdentifier, $newName);
    }
}
