<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Schema;

use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class TableSchemaTool
{
    public function __construct(private TcaSchemaService $tcaSchemaService)
    {
    }

    #[McpTool(
        name: 'table_schema',
        description: 'Get the schema of a database table including field types, labels, select options, and constraints.'
            . ' Use this to discover valid field values before creating or updating records.',
    )]
    public function execute(string $tableName): string
    {
        $schema = $this->tcaSchemaService->getFieldsSchema($tableName);

        if ($schema['fields'] === []) {
            return json_encode(['error' => 'Table not found or has no readable fields: ' . $tableName], JSON_THROW_ON_ERROR);
        }

        return json_encode($schema, JSON_THROW_ON_ERROR);
    }
}
