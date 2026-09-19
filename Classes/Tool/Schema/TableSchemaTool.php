<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Schema;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\TableSchemaResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class TableSchemaTool
{
    public function __construct(private TcaSchemaService $tcaSchemaService, private PermissionService $permissionService)
    {
    }

    #[McpTool(
        name: 'table_schema',
        description: 'Get the schema of a database table including field types, labels, select options, and constraints.'
            . ' Use this to discover valid field values before creating or updating records.',
    )]
    public function execute(string $tableName): TableSchemaResult
    {
        if (!$this->permissionService->canSelectTable($tableName)) {
            throw new ToolCallException('Access denied: you do not have read permission for table: ' . $tableName);
        }

        $schema = $this->tcaSchemaService->getFieldsSchema($tableName);

        if ($schema['fields'] === []) {
            throw new ToolCallException('Table not found or has no readable fields: ' . $tableName);
        }

        return new TableSchemaResult($schema['table'], $schema['fields']);
    }
}
