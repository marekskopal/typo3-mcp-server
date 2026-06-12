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
     * @return T
     * @template T
     */
    public static function run(string $toolName, AuditLogger $auditLogger, LoggerInterface $logger, callable $fn): mixed
    {
        $startTime = hrtime(true);

        try {
            $result = $fn();
            $auditLogger->logSuccess($toolName, 'tool', [], self::elapsedMs($startTime));

            return $result;
        } catch (ToolCallException $e) {
            $auditLogger->logFailure($toolName, 'tool', [], self::elapsedMs($startTime), $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            $auditLogger->logFailure($toolName, 'tool', [], self::elapsedMs($startTime), $e->getMessage());
            $logger->error($toolName . ' failed', ['exception' => $e]);

            throw new ToolCallException('An internal error occurred while executing this tool.', (int) $e->getCode(), $e);
        }
    }

    private static function elapsedMs(int|float $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1000000);
    }
}
