<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Tool\Helper\RegistrarToolRunner;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use Psr\Log\LoggerInterface;

/**
 * Base for the generated table tools.
 *
 * Each subclass is an invokable object whose `__invoke()` signature *is* the tool's signature —
 * the MCP SDK reflects it to build the input schema, and the registrar hands it over as
 * `[$handler, '__invoke']`. That replaces the previous static-closure-inside-a-static-closure
 * shape, where 8-12 variables were threaded through two `use (...)` lists by hand.
 *
 * Subclasses declare their own name and description so the three cannot drift apart, and run
 * their body through {@see run()}, which is the only path to RegistrarToolRunner. That matters:
 * decoding or validating *outside* the runner is what produced TMS-31's missing audit entries,
 * and here there is no outside to put it in.
 *
 * @internal
 */
abstract readonly class AbstractTableToolHandler
{
    public function __construct(protected TableToolConfig $config, protected AuditLogger $auditLogger, protected LoggerInterface $logger)
    {
    }

    abstract public function toolName(): string;

    abstract public function description(): string;

    /**
     * @param callable(): T $fn
     * @param list<mixed> $arguments the call's arguments, for the audit trail (redacted there)
     * @param int $recordUid the record the tool acts on, recorded as the audit-log target
     * @return T
     * @template T
     */
    protected function run(callable $fn, array $arguments, int $recordUid = 0): mixed
    {
        return RegistrarToolRunner::run(
            $this->toolName(),
            $this->auditLogger,
            $this->logger,
            $fn,
            arguments: $arguments,
            tableName: $this->config->tableName,
            recordUid: $recordUid,
        );
    }
}
