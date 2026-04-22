<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class FileUploadTool
{
    public function __construct(private FileService $fileService, private LoggerInterface $logger)
    {
    }

    #[McpTool(
        name: 'file_upload',
        description: 'Upload a file to a storage directory. Provide either "content" for plain text or "base64Content" for base64-encoded binary data. Exactly one must be specified.',
    )]
    public function execute(
        string $fileName,
        string $base64Content = '',
        string $content = '',
        string $directoryPath = '/',
        int $storageUid = 1,
    ): string {
        try {
            $fileContent = $this->resolveContent($base64Content, $content);
            $result = $this->fileService->uploadFile($storageUid, $directoryPath, $fileName, $fileContent);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('file_upload tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    private function resolveContent(string $base64Content, string $content): string
    {
        if ($base64Content !== '' && $content !== '') {
            throw new ToolCallException('Provide either "content" or "base64Content", not both');
        }

        if ($base64Content === '' && $content === '') {
            throw new ToolCallException('Either "content" or "base64Content" must be provided');
        }

        if ($content !== '') {
            return $content;
        }

        $decoded = base64_decode($base64Content, true);
        if ($decoded === false) {
            throw new ToolCallException('Invalid base64 content');
        }

        return $decoded;
    }
}
