<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/** @internal */
final class ErrorHandlingProxy
{
    public function __construct(
        private readonly object $inner,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $auditLogger,
        private readonly string $type,
    ) {
    }

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        $startTime = hrtime(true);

        try {
            $result = $this->inner->$name(...$arguments);
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $this->auditLogger->logSuccess($this->getHandlerName(), $this->type, $arguments, $executionTimeMs);

            return $result;
        } catch (ToolCallException | ResourceReadException $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $this->auditLogger->logFailure(
                $this->getHandlerName(),
                $this->type,
                $arguments,
                $executionTimeMs,
                $e->getMessage(),
            );

            throw $e;
        } catch (\Throwable $e) {
            $executionTimeMs = (int) ((hrtime(true) - $startTime) / 1000000);
            $handlerName = $this->getHandlerName();
            $this->auditLogger->logFailure($handlerName, $this->type, $arguments, $executionTimeMs, $e->getMessage());
            $this->logger->error($handlerName . ' failed', ['exception' => $e]);

            // Don't relay the raw exception message to the client: DBAL exceptions embed the
            // failed SQL and bound parameters, filesystem exceptions embed absolute paths. The
            // full detail is logged above; the client gets a generic message. Tools that want a
            // helpful client-facing message throw ToolCallException/ResourceReadException
            // themselves, which is re-thrown as-is by the catch branch above.
            $genericMessage = sprintf('An internal error occurred while executing this %s.', $this->type);

            if ($this->type === 'resource') {
                throw new ResourceReadException($genericMessage, (int) $e->getCode(), $e);
            }

            throw new ToolCallException($genericMessage, (int) $e->getCode(), $e);
        }
    }

    private function getHandlerName(): string
    {
        return (new ReflectionClass($this->inner))->getShortName();
    }
}
