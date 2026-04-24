<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Resource\Result\TcaTableSchemaResult;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use const JSON_THROW_ON_ERROR;

readonly class TcaTableSchemaResource
{
    public function __construct(private TcaSchemaService $tcaSchemaService)
    {
    }

    #[McpResourceTemplate(
        uriTemplate: 'typo3://schema/tables/{tableName}',
        name: 'tca_table_schema',
        description: 'Full TCA field schema for a specific table including field types, labels, select options, and constraints.',
        mimeType: 'application/json',
    )]
    public function execute(string $tableName): string
    {
        $schema = $this->tcaSchemaService->getFieldsSchema($tableName);

        if ($schema['fields'] === []) {
            throw new ResourceReadException('Table not found or has no readable fields: ' . $tableName);
        }

        $result = new TcaTableSchemaResult(table: $schema['table'], fields: $schema['fields']);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
