<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\Result\FileSearchResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class FileSearchTool
{
    public function __construct(private FileService $fileService)
    {
    }

    #[McpTool(
        name: 'file_search',
        description: 'Search files by name pattern and/or extension in a storage.'
            . ' Use namePattern for LIKE matching on file names (e.g. "logo" matches "logo.png", "my-logo.svg").'
            . ' Use extension to filter by file extension (e.g. "pdf", "jpg").'
            . ' At least one of namePattern or extension must be provided.',
    )]
    public function execute(
        string $namePattern = '',
        string $extension = '',
        int $storageUid = 1,
        int $limit = 20,
        int $offset = 0,
    ): FileSearchResult {
        if ($namePattern === '' && $extension === '') {
            throw new ToolCallException('At least one of namePattern or extension must be provided');
        }

        $result = $this->fileService->searchFiles($storageUid, $namePattern, $extension, $limit, $offset);

        return new FileSearchResult($result['files'], $result['total']);
    }
}
