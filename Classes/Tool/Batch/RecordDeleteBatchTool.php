<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsDeletedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class RecordDeleteBatchTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private RecordService $recordService)
    {
    }

    #[McpTool(
        name: 'record_delete_batch',
        description: 'Delete multiple records from any table in a single operation.'
            . ' Pass UIDs as a comma-separated string (e.g. "1,2,3").'
            . ' Non-existent UIDs are skipped and reported in skippedUids.',
    )]
    public function execute(string $tableName, string $uids): BatchRecordsDeletedResult
    {
        $uidList = $this->parseUids($uids);
        $existingUids = $this->recordService->findExistingUids($tableName, $uidList);

        if ($existingUids === []) {
            throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
        }

        $skippedUids = array_values(array_diff($uidList, $existingUids));
        $this->dataHandlerService->deleteRecords($tableName, $existingUids);

        return new BatchRecordsDeletedResult($existingUids, count($existingUids), $skippedUids);
    }

    /** @return list<int> */
    private function parseUids(string $uids): array
    {
        return array_values(array_filter(
            array_map('intval', array_filter(explode(',', $uids), static fn(string $v): bool => $v !== '')),
            static fn(int $v): bool => $v > 0,
        ));
    }
}
