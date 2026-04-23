<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceListResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class FileReferenceListTool
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'file_reference_list',
        description: 'List all file references attached to a record\'s file/image field.'
            . ' Returns reference UIDs (needed for file_reference_remove), sys_file UIDs (for file_get_info), and metadata overrides.',
    )]
    public function execute(string $table, int $uid, string $fieldName): FileReferenceListResult|ErrorResult
    {
        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            return new ErrorResult(
                'Field \'' . $fieldName . '\' is not a file field on table \'' . $table . '\'',
                ['availableFileFields' => $fileFields],
            );
        }

        try {
            $references = $this->recordService->findFileReferences($table, $uid, $fieldName);
        } catch (\Throwable $e) {
            $this->logger->error('file_reference_list tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new FileReferenceListResult($table, $uid, $fieldName, count($references), $references);
    }
}
