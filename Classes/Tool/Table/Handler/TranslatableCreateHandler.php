<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;

/** `<prefix>_create` for a translatable table: the new record is written in the named language. @internal */
final readonly class TranslatableCreateHandler extends AbstractTableToolHandler
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
        return $this->config->toolName('create');
    }

    public function description(): string
    {
        return 'Create a new ' . $this->config->label . ' record. Pass fields as a JSON object string.'
            . ' Available fields: ' . $this->config->writableFieldList() . '.'
            . ' Use sysLanguageUid to set the language (0 = default, -1 = all languages).';
    }

    public function __invoke(int $pid, string $fields, int $sysLanguageUid = 0): RecordCreatedResult|ErrorResult
    {
        return $this->run(
            fn(): RecordCreatedResult|ErrorResult => RecordCreation::run(
                $this->dataHandlerService,
                $this->config,
                $fields,
                $pid,
                $sysLanguageUid,
            ),
            [$fields, $pid, $sysLanguageUid],
        );
    }
}
