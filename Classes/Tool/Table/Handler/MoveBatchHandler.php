<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\MoveTarget;
use MarekSkopal\MsMcpServer\Tool\Helper\UidListParser;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsMovedResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

/** `<prefix>_move_batch`. @internal */
final readonly class MoveBatchHandler extends AbstractTableToolHandler
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
        return $this->config->toolName('move_batch');
    }

    public function description(): string
    {
        return 'Move multiple ' . $this->config->subject() . 's to a new position in a single operation.'
            . ' Pass UIDs as comma-separated (e.g. "1,2,3").'
            . ' Provide exactly one of: targetPid (move all to the top of that page)'
            . ' or afterUid (place all after that sibling record).'
            . ' Non-existent UIDs are skipped and reported in skippedUids.';
    }

    public function __invoke(string $uids, int $targetPid = -1, int $afterUid = 0): BatchRecordsMovedResult|ErrorResult
    {
        return $this->run(
            function () use ($uids, $targetPid, $afterUid): BatchRecordsMovedResult|ErrorResult {
                $target = MoveTarget::resolve($targetPid, $afterUid);
                if ($target instanceof ErrorResult) {
                    return $target;
                }

                $uidList = UidListParser::parse($uids);
                $existingUids = $this->recordService->findExistingUids($this->config->tableName, $uidList);

                if ($existingUids === []) {
                    throw new ToolCallException('None of the provided UIDs exist in table ' . $this->config->tableName);
                }

                $skippedUids = array_values(array_diff($uidList, $existingUids));

                $this->dataHandlerService->moveRecords($this->config->tableName, $existingUids, $target);

                return new BatchRecordsMovedResult($existingUids, count($existingUids), $target, $skippedUids);
            },
            [$uids, $targetPid, $afterUid],
        );
    }
}
