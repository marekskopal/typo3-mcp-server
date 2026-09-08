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
        description: 'Search records in any table. Pass search either as a plain-text term, which is LIKE-matched'
            . ' against the table\'s label field (TCA ctrl.label, e.g. title), or as a JSON object with field names as keys.'
            . ' A field value can be a plain string for LIKE matching (e.g. {"title":"hello"}), an operator-keyed object'
            . ' (e.g. {"TSconfig":{"like":"tx_news"},"uid":{"gt":"10"}}), the long form {"op":"eq","value":"Home"},'
            . ' a list for IN ({"uid":[1,2]}), true/false (compared against 1/0) or null (IS NULL).'
            . ' Supported operators: eq, neq, like, gt, gte, lt, lte, in (comma-separated or list), null, notNull.'
            . ' A condition of any other shape is rejected with an error rather than silently dropped.'
            . ' Pass an empty string or "{}" for no field filter (useful to list everything in a pid).'
            . ' Optionally filter by pid. Use orderBy to sort results by a field name and orderDirection (ASC or DESC).'
            . ' Returns matching records with pagination.'
            . ' In a non-live workspace, results are workspace-overlaid: the response carries "hasMore"'
            . ' instead of "total" (a SQL COUNT cannot be overlaid) — page with offset until hasMore is false.'
            . ' Many-to-many relation fields (select/group with an MM table) are returned as lists of related UIDs'
            . ' but cannot be used as search conditions; a condition on one is rejected with an error.',
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
        $parsed = SearchParamResolver::parseSearch($search, $allowedFields, $this->tcaSchemaService->getLabelField($tableName));
        $validSearch = $parsed['conditions'];
        $ignoredFields = $parsed['ignoredFields'];

        if ($validSearch === [] && $ignoredFields !== []) {
            return json_encode(
                ['error' => 'No valid search fields provided', 'ignoredFields' => $ignoredFields],
                JSON_THROW_ON_ERROR,
            );
        }

        // An MM field's column holds only the relation count, so ordering by it would be meaningless.
        $orderableFields = array_values(array_diff($allowedFields, array_keys($this->tcaSchemaService->getMMFields($tableName))));
        $resolvedOrderBy = SearchParamResolver::resolveOrderBy($orderBy, $orderableFields);
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
