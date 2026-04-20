<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class RecordSearchTool
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'record_search',
        description: 'Search records in any table by field values (LIKE match). Pass search as a JSON object'
            . ' with field names as keys and search strings as values (e.g. {"title":"hello"}).'
            . ' Optionally filter by pid. Returns matching records with pagination.',
    )]
    public function execute(string $tableName, string $search, int $limit = 20, int $offset = 0, int $pid = -1): string
    {
        $readFields = $this->tcaSchemaService->getReadFields($tableName);
        if ($readFields === ['uid', 'pid']) {
            return json_encode(['error' => 'Table not found or has no readable fields: ' . $tableName], JSON_THROW_ON_ERROR);
        }

        try {
            /** @var array<string, string> $searchData */
            $searchData = json_decode($search, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return json_encode(['error' => 'Invalid JSON in search parameter: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
        }

        // Filter search fields to only allow readable fields
        $allowedFields = array_merge(['uid', 'pid'], $readFields);
        $validSearch = [];
        $ignoredFields = [];
        foreach ($searchData as $field => $value) {
            if (in_array($field, $allowedFields, true)) {
                $validSearch[$field] = $value;
            } else {
                $ignoredFields[] = $field;
            }
        }

        if ($validSearch === []) {
            return json_encode(
                ['error' => 'No valid search fields provided', 'ignoredFields' => $ignoredFields],
                JSON_THROW_ON_ERROR,
            );
        }

        try {
            $result = $this->recordService->search($tableName, $validSearch, $limit, $offset, $readFields, $pid >= 0 ? $pid : null);
        } catch (\Throwable $e) {
            $this->logger->error('record_search tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $response = $result;
        if ($ignoredFields !== []) {
            $response['ignoredFields'] = $ignoredFields;
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }
}
