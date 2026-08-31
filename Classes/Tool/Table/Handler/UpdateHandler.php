<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;

/**
 * `<prefix>_update`.
 *
 * This body used to exist in four near-identical copies (dynamic, redirect, scheduler, and the
 * batch variant) — the drift between them is what let TMS-31's decode escape the audit wrapper.
 *
 * @internal
 */
final readonly class UpdateHandler extends AbstractTableToolHandler
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
        return $this->config->toolName('update');
    }

    public function description(): string
    {
        return 'Update an existing ' . $this->config->subject() . '. Pass fields as a JSON object string'
            . ' with field names and their new values. Available fields: ' . $this->config->writableFieldList() . '.';
    }

    public function __invoke(int $uid, string $fields): RecordUpdatedResult|ErrorResult
    {
        return $this->run(
            function () use ($uid, $fields): RecordUpdatedResult|ErrorResult {
                $data = JsonObjectParser::parse($fields, 'fields');

                $filteredData = array_intersect_key($data, array_flip($this->config->writableFields));
                $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

                if ($filteredData === []) {
                    return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
                }

                $this->dataHandlerService->updateRecord($this->config->tableName, $uid, $filteredData);

                return new RecordUpdatedResult($uid, array_keys($filteredData), $ignoredFields);
            },
            [$uid, $fields],
            $uid,
        );
    }
}
