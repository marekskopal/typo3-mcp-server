<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class RecordCountTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService)
    {
    }

    #[McpTool(
        name: 'record_count',
        description: 'Count records in any table without fetching them. Optionally filter by pid and/or search conditions.'
            . ' Pass search as a JSON object with field names as keys (same format as record_search).'
            . ' Returns only the count, not the records themselves.'
            . ' In a non-live workspace the count is of workspace-overlaid records, matching record_search;'
            . ' an "exact": false in the response means the result set was too large to overlay in full.',
    )]
    public function execute(string $tableName, int $pid = -1, string $search = '',): string
    {
        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            return json_encode(['error' => 'Table not found or has no readable fields: ' . $tableName], JSON_THROW_ON_ERROR);
        }

        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $parsed = SearchParamResolver::parseSearch($search, $allowedFields);
        $searchConditions = $parsed['conditions'];
        $ignoredFields = $parsed['ignoredFields'];

        $count = $this->recordService->count($tableName, $pid >= 0 ? $pid : null, $searchConditions);

        $response = ['table' => $tableName, 'count' => $count['count']];
        // Only ever false in a non-live workspace on a result set too large to overlay in full,
        // where the count is a floor. Say so rather than let it read as exact.
        if (!$count['exact']) {
            $response['exact'] = false;
        }

        if ($ignoredFields !== []) {
            $response['ignoredFields'] = $ignoredFields;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }
}
