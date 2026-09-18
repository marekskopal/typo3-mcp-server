<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceAddedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class FileReferenceAddTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'file_reference_add',
        description: 'Attach uploaded files to a record\'s file/image field. Pass sys_file UIDs from file_upload results.',
    )]
    public function execute(string $table, int $uid, string $fieldName, string $fileUids): FileReferenceAddedResult
    {
        $parsedUids = array_values(array_filter(
            array_map(static fn (string $v): int => (int) trim($v), explode(',', $fileUids)),
            static fn (int $v): bool => $v > 0,
        ));

        if ($parsedUids === []) {
            throw new ToolCallException('No valid file UIDs provided');
        }

        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            throw new ToolCallException(
                'Field \'' . $fieldName . '\' is not a file field on table \'' . $table
                    . '\'. Available file fields: ' . (implode(', ', $fileFields) ?: '(none)'),
            );
        }

        $referenceUids = $this->dataHandlerService->createFileReferences($table, $uid, $fieldName, $parsedUids);

        return new FileReferenceAddedResult($table, $uid, $fieldName, count($referenceUids), $referenceUids);
    }
}
