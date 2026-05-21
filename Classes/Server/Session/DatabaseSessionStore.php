<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server\Session;

use MarekSkopal\MsMcpServer\Repository\McpSessionRepository;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

readonly class DatabaseSessionStore implements SessionStoreInterface
{
    /** @param positive-int $ttlSeconds */
    public function __construct(private McpSessionRepository $repository, private int $ttlSeconds,)
    {
    }

    public function exists(Uuid $id): bool
    {
        $sessionId = $id->toRfc4122();
        $row = $this->repository->findBySessionId($sessionId);
        if ($row === null) {
            return false;
        }

        $now = time();
        if ($row['last_activity'] < $now - $this->ttlSeconds) {
            $this->repository->delete($sessionId);

            return false;
        }

        $this->repository->touch($sessionId, $now);

        return true;
    }

    public function read(Uuid $id): string|false
    {
        $sessionId = $id->toRfc4122();
        $row = $this->repository->findBySessionId($sessionId);
        if ($row === null) {
            return false;
        }

        $now = time();
        if ($row['last_activity'] < $now - $this->ttlSeconds) {
            $this->repository->delete($sessionId);

            return false;
        }

        $this->repository->touch($sessionId, $now);

        return $row['data'];
    }

    public function write(Uuid $id, string $data): bool
    {
        $this->repository->upsert($id->toRfc4122(), $data, time());

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        return $this->repository->delete($id->toRfc4122());
    }

    /** @return Uuid[] */
    public function gc(): array
    {
        $this->repository->deleteExpired(time() - $this->ttlSeconds);

        return [];
    }
}
