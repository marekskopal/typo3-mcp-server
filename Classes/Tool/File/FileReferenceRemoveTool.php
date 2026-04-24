<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceRemovedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class FileReferenceRemoveTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'file_reference_remove',
        description: 'Remove file references by their UIDs (from file_reference_list results).'
            . ' This detaches files from the record but does not delete the underlying files.',
    )]
    public function execute(string $referenceUids): FileReferenceRemovedResult|ErrorResult
    {
        $parsedUids = array_values(array_filter(
            array_map(static fn (string $v): int => (int) trim($v), explode(',', $referenceUids)),
            static fn (int $v): bool => $v > 0,
        ));

        if ($parsedUids === []) {
            return new ErrorResult('No valid reference UIDs provided');
        }

        foreach ($parsedUids as $referenceUid) {
            $this->dataHandlerService->deleteRecord('sys_file_reference', $referenceUid);
        }

        return new FileReferenceRemovedResult(count($parsedUids), $parsedUids);
    }
}
