<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class ContentSearchTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService)
    {
    }

    #[McpTool(
        name: 'content_search',
        description: 'Search content elements by header or other fields. Pass a plain text string for LIKE matching on header'
            . ' (e.g. "hello") or a JSON object for advanced conditions'
            . ' (e.g. {"CType":{"op":"eq","value":"text"}, "header":"Welcome"}).'
            . ' Supports operators: eq, neq, like, gt, gte, lt, lte, in, null, notNull.'
            . ' Filter by pid and/or sysLanguageUid. Use orderBy and orderDirection for sorting.',
    )]
    public function execute(
        string $search,
        int $limit = 20,
        int $offset = 0,
        int $pid = -1,
        int $sysLanguageUid = -1,
        string $orderBy = '',
        string $orderDirection = 'ASC',
    ): string {
        $readFields = $this->tcaSchemaService->getReadFields('tt_content');
        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $searchConditions = SearchParamResolver::parseSearch($search, $allowedFields, 'header')['conditions'];

        if ($searchConditions === []) {
            return json_encode(['error' => 'No valid search conditions provided'], JSON_THROW_ON_ERROR);
        }

        if ($sysLanguageUid >= 0) {
            $searchConditions['sys_language_uid'] = ['operator' => 'eq', 'value' => (string) $sysLanguageUid];
        }

        $resolvedOrderBy = SearchParamResolver::resolveOrderBy($orderBy, $allowedFields);
        $orderDirection = SearchParamResolver::normalizeOrderDirection($orderDirection);

        return json_encode(
            $this->recordService->search(
                'tt_content',
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
