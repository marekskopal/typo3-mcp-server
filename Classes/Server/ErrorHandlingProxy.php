<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use Mcp\Exception\ResourceReadException;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/** @internal */
final class ErrorHandlingProxy
{
    public function __construct(private readonly object $inner, private readonly LoggerInterface $logger, private readonly string $type)
    {
    }

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        try {
            return $this->inner->$name(...$arguments);
        } catch (ToolCallException | ResourceReadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $className = (new ReflectionClass($this->inner))->getShortName();
            $this->logger->error($className . ' failed', ['exception' => $e]);

            if ($this->type === 'resource') {
                throw new ResourceReadException($e->getMessage(), (int) $e->getCode(), $e);
            }

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
