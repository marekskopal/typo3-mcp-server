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
            . ' Returns only the count, not the records themselves.',
    )]
    public function execute(string $tableName, int $pid = -1, string $search = '',): string
    {
        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            return json_encode(['error' => 'Table not found or has no readable fields: ' . $tableName], JSON_THROW_ON_ERROR);
        }

        $searchConditions = [];
        $ignoredFields = [];

        if ($search !== '') {
            try {
                /** @var array<string, mixed> $searchData */
                $searchData = json_decode($search, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return json_encode(
                    ['error' => 'Invalid JSON in search parameter: ' . $e->getMessage()],
                    JSON_THROW_ON_ERROR,
                );
            }

            $allowedFields = array_merge(['uid', 'pid'], $readFields);
            $searchConditions = SearchConditionParser::fromArray($searchData, $allowedFields);
            $ignoredFields = array_values(array_diff(array_keys($searchData), $allowedFields));
        }

        $count = $this->recordService->count($tableName, $pid >= 0 ? $pid : null, $searchConditions);

        $response = ['table' => $tableName, 'count' => $count];
        if ($ignoredFields !== []) {
            $response['ignoredFields'] = $ignoredFields;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }
}
