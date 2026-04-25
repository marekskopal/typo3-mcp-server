<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsMovedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class RecordMoveBatchTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'record_move_batch',
        description: 'Move multiple records to a new position in a single operation.'
            . ' Pass UIDs as comma-separated (e.g. "1,2,3").'
            . ' Positive target = move to page (target = pid). Negative target = move after record (target = -uid).',
    )]
    public function execute(string $tableName, string $uids, int $target): BatchRecordsMovedResult
    {
        $uidList = $this->parseUids($uids);
        $this->dataHandlerService->moveRecords($tableName, $uidList, $target);

        return new BatchRecordsMovedResult($uidList, count($uidList), $target);
    }

    /** @return list<int> */
    private function parseUids(string $uids): array
    {
        return array_values(array_map('intval', array_filter(explode(',', $uids), static fn (string $v): bool => $v !== '')));
    }
}
