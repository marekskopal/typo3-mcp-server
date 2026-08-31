<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class RecordSearchTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'record_search',
        description: 'Search records in any table by field conditions. Pass search as a JSON object with field names as keys.'
            . ' Values can be a plain string for LIKE matching (e.g. {"title":"hello"}) or an object with "op" and "value"'
            . ' for advanced operators (e.g. {"uid":{"op":"gt","value":"10"}, "title":{"op":"eq","value":"Home"}}).'
            . ' Supported operators: eq, neq, like, gt, gte, lt, lte, in (comma-separated), null, notNull.'
            . ' Pass an empty string or "{}" for no field filter (useful to list everything in a pid).'
            . ' Optionally filter by pid. Use orderBy to sort results by a field name and orderDirection (ASC or DESC).'
            . ' Returns matching records with pagination.',
    )]
    public function execute(
        string $tableName,
        string $search,
        int $limit = 20,
        int $offset = 0,
        int $pid = -1,
        string $orderBy = '',
        string $orderDirection = 'ASC',
    ): string
    {
        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            return json_encode(['error' => 'Table not found or has no readable fields: ' . $tableName], JSON_THROW_ON_ERROR);
        }

        // Filter search fields to only allow readable fields and parse conditions
        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $parsed = SearchParamResolver::parseSearch($search, $allowedFields);
        $validSearch = $parsed['conditions'];
        $ignoredFields = $parsed['ignoredFields'];

        if ($validSearch === [] && $ignoredFields !== []) {
            return json_encode(
                ['error' => 'No valid search fields provided', 'ignoredFields' => $ignoredFields],
                JSON_THROW_ON_ERROR,
            );
        }

        $resolvedOrderBy = SearchParamResolver::resolveOrderBy($orderBy, $allowedFields);
        $orderDirection = SearchParamResolver::normalizeOrderDirection($orderDirection);

        $result = $this->recordService->search(
            $tableName,
            $validSearch,
            $limit,
            $offset,
            $readFields,
            $pid >= 0 ? $pid : null,
            $resolvedOrderBy,
            $orderDirection,
        );

        $response = $result;
        if ($ignoredFields !== []) {
            $response['ignoredFields'] = $ignoredFields;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }
}
