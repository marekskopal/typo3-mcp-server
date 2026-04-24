<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceAddedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileReferenceAddTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'file_reference_add',
        description: 'Attach uploaded files to a record\'s file/image field. Pass sys_file UIDs from file_upload results.',
    )]
    public function execute(string $table, int $uid, string $fieldName, string $fileUids): FileReferenceAddedResult|ErrorResult
    {
        $parsedUids = array_values(array_filter(
            array_map(static fn (string $v): int => (int) trim($v), explode(',', $fileUids)),
            static fn (int $v): bool => $v > 0,
        ));

        if ($parsedUids === []) {
            return new ErrorResult('No valid file UIDs provided');
        }

        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            return new ErrorResult(
                'Field \'' . $fieldName . '\' is not a file field on table \'' . $table . '\'',
                ['availableFileFields' => $fileFields],
            );
        }

        $referenceUids = $this->dataHandlerService->createFileReferences($table, $uid, $fieldName, $parsedUids);

        return new FileReferenceAddedResult($table, $uid, $fieldName, count($referenceUids), $referenceUids);
    }
}
