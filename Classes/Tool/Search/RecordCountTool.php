<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCountResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class RecordCountTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService)
    {
    }

    #[McpTool(
        name: 'record_count',
        description: 'Count records in any table without fetching them. Optionally filter by pid and/or search conditions.'
            . ' Pass search as a plain-text term (LIKE-matched against the table\'s label field) or as a JSON object'
            . ' with field names as keys, in the same format as record_search, e.g. {"TSconfig":{"like":"tx_news"}}.'
            . ' Returns only the count, not the records themselves.'
            . ' In a non-live workspace the count is of workspace-overlaid records, matching record_search;'
            . ' an "exact": false in the response means the result set was too large to overlay in full.'
            . ' Many-to-many relation fields cannot be used as search conditions and are rejected with an error.',
    )]
    public function execute(string $tableName, int $pid = -1, string $search = '',): RecordCountResult
    {
        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            throw new ToolCallException('Table not found or has no readable fields: ' . $tableName);
        }

        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $parsed = SearchParamResolver::parseSearch($search, $allowedFields, $this->tcaSchemaService->getLabelField($tableName));
        $searchConditions = $parsed['conditions'];
        $ignoredFields = $parsed['ignoredFields'];

        $count = $this->recordService->count($tableName, $pid >= 0 ? $pid : null, $searchConditions);

        return new RecordCountResult($tableName, $count['count'], $count['exact'], $ignoredFields);
    }
}
