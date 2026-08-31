<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
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
        private RecordService $recordService,
    ) {
        parent::__construct($config, $auditLogger, $logger);
    }

    public function toolName(): string
    {
        return $this->config->toolName('delete');
    }

    public function description(): string
    {
        return 'Delete a ' . $this->config->subject() . ' by its uid.'
            . ' Set dryRun to true to check what would happen without deleting anything.';
    }

    public function __invoke(int $uid, bool $dryRun = false): RecordDeletedResult|ErrorResult
    {
        return $this->run(
            function () use ($uid, $dryRun): RecordDeletedResult|ErrorResult {
                if ($dryRun) {
                    // A preview that skipped this would answer "would delete" for a uid that does
                    // not exist or that this user cannot see. findByUid() applies the same read
                    // permissions, so the preview agrees with what the real call would do.
                    if ($this->recordService->findByUid($this->config->tableName, $uid, ['uid']) === null) {
                        return new ErrorResult($this->config->subjectSentenceStart() . ' not found: ' . $uid);
                    }

                    return new RecordDeletedResult($uid, dryRun: true);
                }

                $this->dataHandlerService->deleteRecord($this->config->tableName, $uid);

                return new RecordDeletedResult($uid);
            },
            [$uid, $dryRun],
            $uid,
        );
    }
}
