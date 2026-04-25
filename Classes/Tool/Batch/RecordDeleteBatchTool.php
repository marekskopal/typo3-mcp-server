<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsDeletedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class RecordDeleteBatchTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'record_delete_batch',
        description: 'Delete multiple records from any table in a single operation.'
            . ' Pass UIDs as a comma-separated string (e.g. "1,2,3").',
    )]
    public function execute(string $tableName, string $uids): BatchRecordsDeletedResult
    {
        $uidList = $this->parseUids($uids);
        $this->dataHandlerService->deleteRecords($tableName, $uidList);

        return new BatchRecordsDeletedResult($uidList, count($uidList));
    }

    /** @return list<int> */
    private function parseUids(string $uids): array
    {
        return array_values(array_map('intval', array_filter(explode(',', $uids), static fn (string $v): bool => $v !== '')));
    }
}
