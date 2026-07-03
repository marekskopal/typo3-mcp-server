<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

/**
 * Shared execution wrapper for registrar-based (closure) tools. Those tools are registered
 * directly on the MCP builder and therefore bypass ErrorHandlingProxy, so without this they
 * get neither audit logging nor client-facing error sanitisation. This brings them to parity
 * with attribute-discovered tools: every invocation is written to sys_log, and raw exception
 * messages (DBAL SQL, filesystem paths) are not relayed to the client.
 *
 * @internal
 */
final class RegistrarToolRunner
{
    /**
     * @param callable(): T $fn
     * @param list<mixed> $arguments the tool call's arguments, for the audit trail (redacted there)
     * @param string $tableName table the tool operates on, recorded as the audit-log target
     * @param int $recordUid primary record uid the tool operates on, recorded as the audit-log target
     * @return T
     * @template T
     */
    public static function run(
        string $toolName,
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        callable $fn,
        array $arguments = [],
        string $tableName = '',
        int $recordUid = 0,
    ): mixed {
        $startTime = hrtime(true);

        try {
            $result = $fn();
            $auditLogger->logSuccess($toolName, 'tool', $arguments, self::elapsedMs($startTime), $tableName, $recordUid);

            return $result;
        } catch (ToolCallException $e) {
            $auditLogger->logFailure($toolName, 'tool', $arguments, self::elapsedMs($startTime), $e->getMessage(), $tableName, $recordUid);

            throw $e;
        } catch (\Throwable $e) {
            $auditLogger->logFailure($toolName, 'tool', $arguments, self::elapsedMs($startTime), $e->getMessage(), $tableName, $recordUid);
            $logger->error($toolName . ' failed', ['exception' => $e]);

            throw new ToolCallException('An internal error occurred while executing this tool.', (int) $e->getCode(), $e);
        }
    }

    private static function elapsedMs(int|float $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1000000);
    }
}
