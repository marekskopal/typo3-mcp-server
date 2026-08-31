<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

/**
 * `<prefix>_list` for a table without translations. The translatable variant is
 * {@see TranslatableListHandler}: the two differ in signature, and the signature is the tool's
 * schema, so they cannot be one class with an optional parameter.
 *
 * @internal
 */
final readonly class ListHandler extends AbstractTableToolHandler
{
    public function __construct(
        TableToolConfig $config,
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        private RecordService $recordService,
    ) {
        parent::__construct($config, $auditLogger, $logger);
    }

    public function toolName(): string
    {
        return $this->config->toolName('list');
    }

    public function description(): string
    {
        return 'List ' . $this->config->subject() . 's by parent page ID with pagination.'
            . ' Use selectFields (comma-separated) to choose which fields to return.';
    }

    public function __invoke(int $pid = 0, int $limit = 20, int $offset = 0, string $selectFields = ''): string
    {
        return $this->run(
            function () use ($pid, $limit, $offset, $selectFields): string {
                $result = $this->recordService->findByPid(
                    $this->config->tableName,
                    $pid,
                    $limit,
                    $offset,
                    SelectedFields::resolve($selectFields, $this->config),
                );

                return json_encode($result, JSON_THROW_ON_ERROR);
            },
            [$pid, $limit, $offset, $selectFields],
        );
    }
}
