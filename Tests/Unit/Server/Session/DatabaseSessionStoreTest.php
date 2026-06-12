<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server\Session;

use MarekSkopal\MsMcpServer\Repository\McpSessionRepository;
use MarekSkopal\MsMcpServer\Server\Session\DatabaseSessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DatabaseSessionStore::class)]
final class DatabaseSessionStoreTest extends TestCase
{
    private const int BE_USER = 42;

    private Uuid $uuid;

    private string $sessionId;

    protected function setUp(): void
    {
        $this->uuid = Uuid::v4();
        $this->sessionId = $this->uuid->toRfc4122();
    }

    public function testExistsReturnsFalseForUnknownSession(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn(null);
        $repository->expects(self::never())->method('touch');

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertFalse($store->exists($this->uuid));
    }

    public function testExistsReturnsTrueAndTouchesSlidingTtl(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->with($this->sessionId)->willReturn($this->row(time()));
        $repository->expects(self::once())->method('touch')->with($this->sessionId, self::isInt());

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertTrue($store->exists($this->uuid));
    }

    public function testExistsDeletesAndReturnsFalseWhenExpired(): void
    {
        // Activity stamped 2 days ago; ttl is 1 day.
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($this->row(time() - 172_800));
        $repository->expects(self::once())->method('delete')->with($this->sessionId);
        $repository->expects(self::never())->method('touch');

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertFalse($store->exists($this->uuid));
    }

    public function testExistsReturnsFalseForSessionOwnedByAnotherUser(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($this->row(time(), 99));
        $repository->expects(self::never())->method('touch');
        $repository->expects(self::never())->method('delete');

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertFalse($store->exists($this->uuid));
    }

    public function testReadReturnsDataAndTouchesSession(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($this->row(time()));
        $repository->expects(self::once())->method('touch')->with($this->sessionId, self::isInt());

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertSame('payload', $store->read($this->uuid));
    }

    public function testReadReturnsFalseForExpiredAndDeletesRow(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($this->row(time() - 172_800));
        $repository->expects(self::once())->method('delete')->with($this->sessionId);
        $repository->expects(self::never())->method('touch');

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertFalse($store->read($this->uuid));
    }

    public function testReadReturnsFalseForSessionOwnedByAnotherUser(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($this->row(time(), 99));
        $repository->expects(self::never())->method('touch');

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertFalse($store->read($this->uuid));
    }

    public function testWriteUpsertsWithOwnerAndCurrentTimestamp(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->expects(self::once())
            ->method('upsert')
            ->with($this->sessionId, self::BE_USER, 'data', self::isInt());

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertTrue($store->write($this->uuid, 'data'));
    }

    public function testDestroyDeletesWhenOwnedByCurrentUser(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($this->row(time()));
        $repository->expects(self::once())->method('delete')->with($this->sessionId)->willReturn(true);

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertTrue($store->destroy($this->uuid));
    }

    public function testDestroyDoesNotDeleteSessionOwnedByAnotherUser(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->method('findBySessionId')->willReturn($this->row(time(), 99));
        $repository->expects(self::never())->method('delete');

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertFalse($store->destroy($this->uuid));
    }

    public function testGcCallsDeleteExpiredAndReturnsEmptyArray(): void
    {
        $repository = $this->createMock(McpSessionRepository::class);
        $repository->expects(self::once())
            ->method('deleteExpired')
            ->with(self::isInt())
            ->willReturn(3);

        $store = new DatabaseSessionStore($repository, 86400, self::BE_USER);

        self::assertSame([], $store->gc());
    }

    /** @return array{session_id: string, be_user: int, data: string, last_activity: int} */
    private function row(int $lastActivity, int $beUser = self::BE_USER): array
    {
        return [
            'session_id' => $this->sessionId,
            'be_user' => $beUser,
            'data' => 'payload',
            'last_activity' => $lastActivity,
        ];
    }
}
