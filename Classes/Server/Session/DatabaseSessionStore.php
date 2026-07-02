<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server\Session;

use MarekSkopal\MsMcpServer\Repository\McpSessionRepository;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

readonly class DatabaseSessionStore implements SessionStoreInterface
{
    /** @param positive-int $ttlSeconds */
    public function __construct(private McpSessionRepository $repository, private int $ttlSeconds, private int $beUserUid,)
    {
    }

    public function exists(Uuid $id): bool
    {
        return $this->readData($id) !== false;
    }

    public function read(Uuid $id): string|false
    {
        return $this->readData($id);
    }

    public function write(Uuid $id, string $data): bool
    {
        $sessionId = $id->toRfc4122();
        $row = $this->repository->findBySessionId($sessionId);

        // Never overwrite a session owned by another backend user; mirrors the ownership checks on
        // read()/destroy() so a client cannot clobber or take over another user's session data.
        if ($row !== null && $row['be_user'] !== $this->beUserUid) {
            return false;
        }

        $this->repository->upsert($sessionId, $this->beUserUid, $data, time());

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        $sessionId = $id->toRfc4122();
        $row = $this->repository->findBySessionId($sessionId);

        // Only the owning backend user may destroy a session.
        if ($row === null || $row['be_user'] !== $this->beUserUid) {
            return false;
        }

        return $this->repository->delete($sessionId);
    }

    /**
     * Shared read path for exists()/read(): enforces ownership and sliding TTL, and
     * bumps last_activity on a hit. Returns the stored data, or false when the session
     * is absent, expired, or owned by a different backend user.
     */
    private function readData(Uuid $id): string|false
    {
        $sessionId = $id->toRfc4122();
        $row = $this->repository->findBySessionId($sessionId);
        if ($row === null) {
            return false;
        }

        // Refuse to surface or attach to a session that belongs to another backend user,
        // even with a valid token, so a leaked session id can't be hijacked cross-user.
        if ($row['be_user'] !== $this->beUserUid) {
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

    /** @return Uuid[] */
    public function gc(): array
    {
        $this->repository->deleteExpired(time() - $this->ttlSeconds);

        return [];
    }
}
