<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Helper\MoveTarget;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordMovedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;

/** `<prefix>_move`. @internal */
final readonly class MoveHandler extends AbstractTableToolHandler
{
    public function __construct(
        TableToolConfig $config,
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        private DataHandlerService $dataHandlerService,
    ) {
        parent::__construct($config, $auditLogger, $logger);
    }

    public function toolName(): string
    {
        return $this->config->toolName('move');
    }

    public function description(): string
    {
        return 'Move a ' . $this->config->label . ' record to a new position. Provide exactly one of:'
            . ' targetPid (move to the top of that page) or afterUid (place after that sibling record).';
    }

    public function __invoke(int $uid, int $targetPid = -1, int $afterUid = 0): RecordMovedResult|ErrorResult
    {
        return $this->run(
            function () use ($uid, $targetPid, $afterUid): RecordMovedResult|ErrorResult {
                $target = MoveTarget::resolve($targetPid, $afterUid);
                if ($target instanceof ErrorResult) {
                    return $target;
                }

                $this->dataHandlerService->moveRecord($this->config->tableName, $uid, $target);

                return new RecordMovedResult($uid, $target);
            },
            [$uid, $targetPid, $afterUid],
            $uid,
        );
    }
}
