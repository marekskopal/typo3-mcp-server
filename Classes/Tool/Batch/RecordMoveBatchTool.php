<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsMovedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class RecordMoveBatchTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private RecordService $recordService)
    {
    }

    #[McpTool(
        name: 'record_move_batch',
        description: 'Move multiple records to a new position in a single operation.'
            . ' Pass UIDs as comma-separated (e.g. "1,2,3").'
            . ' Positive target = move to page (target = pid). Negative target = move after record (target = -uid).'
            . ' Non-existent UIDs are skipped and reported in skippedUids.',
    )]
    public function execute(string $tableName, string $uids, int $target): BatchRecordsMovedResult
    {
        $uidList = $this->parseUids($uids);
        $existingUids = $this->recordService->findExistingUids($tableName, $uidList);

        if ($existingUids === []) {
            throw new ToolCallException('None of the provided UIDs exist in table ' . $tableName);
        }

        $skippedUids = array_values(array_diff($uidList, $existingUids));
        $this->dataHandlerService->moveRecords($tableName, $existingUids, $target);

        return new BatchRecordsMovedResult($existingUids, count($existingUids), $target, $skippedUids);
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
