<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Decorating container that wraps tool and resource instances with centralized error handling.
 * Converts uncaught \Throwable exceptions to ToolCallException or ResourceReadException,
 * removing the need for try/catch boilerplate in every tool and resource class.
 */
readonly class ErrorHandlingContainer implements ContainerInterface
{
    /** @param array<class-string, 'tool'|'resource'> $handlerTypes */
    public function __construct(
        private ContainerInterface $inner,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
        private array $handlerTypes,
    ) {
    }

    public function get(string $id): mixed
    {
        $instance = $this->inner->get($id);

        $type = $this->handlerTypes[$id] ?? null;
        if ($type === null || !is_object($instance)) {
            return $instance;
        }

        return new ErrorHandlingProxy($instance, $this->logger, $this->auditLogger, $type);
    }

    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }
}
