<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class PagesSearchTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService)
    {
    }

    #[McpTool(
        name: 'pages_search',
        description: 'Search pages by title or other fields. Pass a plain text string for LIKE matching on title'
            . ' (e.g. "hello") or a JSON object for advanced conditions'
            . ' (e.g. {"doktype":{"op":"eq","value":"1"}, "title":"Home"}).'
            . ' Supports operators: eq, neq, like, gt, gte, lt, lte, in, null, notNull.'
            . ' Use orderBy and orderDirection for sorting.'
            . ' In a non-live workspace, results are workspace-overlaid: the response carries "hasMore"'
            . ' instead of "total" (a SQL COUNT cannot be overlaid) — page with offset until hasMore is false.',
    )]
    public function execute(
        string $search,
        int $limit = 20,
        int $offset = 0,
        int $pid = -1,
        string $orderBy = '',
        string $orderDirection = 'ASC',
    ): string {
        $readFields = $this->tcaSchemaService->getReadFields('pages');
        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $searchConditions = SearchParamResolver::parseSearch($search, $allowedFields, 'title')['conditions'];

        if ($searchConditions === []) {
            return json_encode(['error' => 'No valid search conditions provided'], JSON_THROW_ON_ERROR);
        }

        $resolvedOrderBy = SearchParamResolver::resolveOrderBy($orderBy, $allowedFields);
        $orderDirection = SearchParamResolver::normalizeOrderDirection($orderDirection);

        return json_encode(
            $this->recordService->search(
                'pages',
                $searchConditions,
                $limit,
                $offset,
                $readFields,
                $pid >= 0 ? $pid : null,
                $resolvedOrderBy,
                $orderDirection,
            ),
            JSON_THROW_ON_ERROR,
        );
    }
}
