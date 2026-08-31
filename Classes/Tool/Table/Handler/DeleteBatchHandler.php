<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\UidListParser;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

/** `<prefix>_delete_batch`. @internal */
final readonly class DeleteBatchHandler extends AbstractTableToolHandler
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
        return $this->config->toolName('delete_batch');
    }

    public function description(): string
    {
        return 'Delete multiple ' . $this->config->label . ' records in a single operation.'
            . ' Pass UIDs as a comma-separated string (e.g. "1,2,3").'
            . ' Non-existent UIDs are skipped and reported in skippedUids.';
    }

    public function __invoke(string $uids): BatchRecordsDeletedResult
    {
        return $this->run(
            function () use ($uids): BatchRecordsDeletedResult {
                $uidList = UidListParser::parse($uids);
                $existingUids = $this->recordService->findExistingUids($this->config->tableName, $uidList);

                if ($existingUids === []) {
                    throw new ToolCallException('None of the provided UIDs exist in table ' . $this->config->tableName);
                }

                $skippedUids = array_values(array_diff($uidList, $existingUids));

                $this->dataHandlerService->deleteRecords($this->config->tableName, $existingUids);

                return new BatchRecordsDeletedResult($existingUids, count($existingUids), $skippedUids);
            },
            [$uids],
        );
    }
}
