<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Helper\CreatePosition;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;

/**
 * `<prefix>_create` for a table without translations. The translatable variant is
 * {@see TranslatableCreateHandler}.
 *
 * @internal
 */
final readonly class CreateHandler extends AbstractTableToolHandler
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
        return $this->config->toolName('create');
    }

    public function description(): string
    {
        return 'Create a new ' . $this->config->subject() . '. Pass fields as a JSON object string.'
            . ' Available fields: ' . $this->config->writableFieldList() . '.' . $this->config->mmFieldHint()
            . CreatePosition::DESCRIPTION;
    }

    public function __invoke(int $pid, string $fields, int $afterUid = 0, string $position = CreatePosition::BOTTOM,): RecordCreatedResult
    {
        return $this->run(
            fn(): RecordCreatedResult => RecordCreation::run(
                $this->dataHandlerService,
                $this->recordService,
                $this->config,
                $fields,
                $pid,
                null,
                $afterUid,
                $position,
            ),
            [$fields, $pid, 0, $afterUid, $position],
        );
    }
}
