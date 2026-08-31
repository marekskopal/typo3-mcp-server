<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;

/** `<prefix>_delete`. @internal */
final readonly class DeleteHandler extends AbstractTableToolHandler
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
        return $this->config->toolName('delete');
    }

    public function description(): string
    {
        return 'Delete a ' . $this->config->label . ' record by its uid.';
    }

    public function __invoke(int $uid): RecordDeletedResult
    {
        return $this->run(
            function () use ($uid): RecordDeletedResult {
                $this->dataHandlerService->deleteRecord($this->config->tableName, $uid);

                return new RecordDeletedResult($uid);
            },
            [$uid],
            $uid,
        );
    }
}
