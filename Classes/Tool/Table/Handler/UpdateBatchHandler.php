<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use MarekSkopal\MsMcpServer\Tool\Helper\UidListParser;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsUpdatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

/** `<prefix>_update_batch`. @internal */
final readonly class UpdateBatchHandler extends AbstractTableToolHandler
{
    public function __construct(
        TableToolConfig $config,
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        private RecordService $recordService,
        private DataHandlerService $dataHandlerService,
    ) {
        parent::__construct($config, $auditLogger, $logger);
    }

    public function toolName(): string
    {
        return $this->config->toolName('update_batch');
    }

    public function description(): string
    {
        return 'Update the same fields on multiple ' . $this->config->subject() . 's.'
            . ' Pass UIDs as comma-separated (e.g. "1,2,3") and fields as a JSON object (e.g. {"hidden":1}).'
            . ' Available fields: ' . $this->config->writableFieldList() . '.'
            . ' Non-existent UIDs are skipped and reported in skippedUids.'
            . ' Set dryRun to true to preview the change: the response lists exactly what would be'
            . ' affected and nothing is written.';
    }

    public function __invoke(string $uids, string $fields, bool $dryRun = false): BatchRecordsUpdatedResult
    {
        return $this->run(
            function () use ($uids, $fields, $dryRun): BatchRecordsUpdatedResult {
                $uidList = UidListParser::parse($uids);
                $existingUids = $this->recordService->findExistingUids($this->config->tableName, $uidList);

                if ($existingUids === []) {
                    throw new ToolCallException('None of the provided UIDs exist in table ' . $this->config->tableName);
                }

                $skippedUids = array_values(array_diff($uidList, $existingUids));

                $fieldData = JsonObjectParser::parse($fields, 'fields');

                $validFields = [];
                $ignoredFields = [];
                foreach ($fieldData as $field => $value) {
                    if (in_array($field, $this->config->writableFields, true)) {
                        $validFields[$field] = $value;
                    } else {
                        $ignoredFields[] = $field;
                    }
                }

                if ($validFields === []) {
                    throw new ToolCallException('No valid writable fields provided');
                }

                if (!$dryRun) {
                    $this->dataHandlerService->updateRecords($this->config->tableName, $existingUids, $validFields);
                }

                return new BatchRecordsUpdatedResult(
                    $existingUids,
                    count($existingUids),
                    array_keys($validFields),
                    $ignoredFields,
                    $skippedUids,
                    $dryRun,
                );
            },
            [$uids, $fields, $dryRun],
        );
    }
}
