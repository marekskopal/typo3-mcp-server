<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceRemovedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class FileReferenceRemoveTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
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

        try {
            foreach ($parsedUids as $referenceUid) {
                $this->dataHandlerService->deleteRecord('sys_file_reference', $referenceUid);
            }
        } catch (\Throwable $e) {
            $this->logger->error('file_reference_remove tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new FileReferenceRemovedResult(count($parsedUids), $parsedUids);
    }
}
