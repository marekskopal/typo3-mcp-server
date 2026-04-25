<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsUpdatedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use const JSON_THROW_ON_ERROR;

readonly class RecordUpdateBatchTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private TcaSchemaService $tcaSchemaService)
    {
    }

    #[McpTool(
        name: 'record_update_batch',
        description: 'Update the same fields on multiple records in any table.'
            . ' Pass UIDs as comma-separated (e.g. "1,2,3") and fields as a JSON object (e.g. {"hidden":1}).'
            . ' All specified records will be updated with the same field values.',
    )]
    public function execute(string $tableName, string $uids, string $fields): BatchRecordsUpdatedResult
    {
        $uidList = $this->parseUids($uids);
        $writableFields = $this->tcaSchemaService->getWritableFields($tableName);

        /** @var array<string, mixed> $fieldData */
        $fieldData = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $validFields = [];
        $ignoredFields = [];
        foreach ($fieldData as $field => $value) {
            if (in_array($field, $writableFields, true)) {
                $validFields[$field] = $value;
            } else {
                $ignoredFields[] = $field;
            }
        }

        if ($validFields === []) {
            throw new ToolCallException('No valid writable fields provided');
        }

        $this->dataHandlerService->updateRecords($tableName, $uidList, $validFields);

        return new BatchRecordsUpdatedResult($uidList, count($uidList), array_keys($validFields), $ignoredFields);
    }

    /** @return list<int> */
    private function parseUids(string $uids): array
    {
        return array_values(array_map('intval', array_filter(explode(',', $uids), static fn (string $v): bool => $v !== '')));
    }
}
