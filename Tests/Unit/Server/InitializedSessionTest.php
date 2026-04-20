<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use MarekSkopal\MsMcpServer\Server\InitializedSession;
use Mcp\Server\Session\SessionStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(InitializedSession::class)]
final class InitializedSessionTest extends TestCase
{
    public function testGetIdReturnsUuid(): void
    {
        $id = Uuid::v4();
        $session = new InitializedSession($this->createStub(SessionStoreInterface::class), $id);

        self::assertSame($id, $session->getId());
    }

    public function testGetStoreReturnsStore(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $session = new InitializedSession($store, Uuid::v4());

        self::assertSame($store, $session->getStore());
    }

    public function testSetAndGetValue(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('foo', 'bar');
        self::assertSame('bar', $session->get('foo'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        self::assertSame('default', $session->get('missing', 'default'));
    }

    public function testNestedSetAndGet(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('a.b.c', 'deep');
        self::assertSame('deep', $session->get('a.b.c'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('exists', true);
        self::assertTrue($session->has('exists'));
        self::assertFalse($session->has('missing'));
    }

    public function testForgetRemovesKey(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('key', 'value');
        $session->forget('key');
        self::assertNull($session->get('key'));
    }

    public function testPullReturnsAndRemoves(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('temp', 42);
        self::assertSame(42, $session->pull('temp'));
        self::assertNull($session->get('temp'));
    }

    public function testClearRemovesAllData(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('a', 1);
        $session->set('b', 2);
        $session->clear();

        self::assertSame([], $session->all());
    }

    public function testAllReturnsAllData(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('x', 'y');
        $all = $session->all();

        self::assertSame(['x' => 'y'], $all);
    }

    public function testSaveWritesToStore(): void
    {
        $store = $this->createMock(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $store->expects(self::once())->method('write')->willReturn(true);

        $id = Uuid::v4();
        $session = new InitializedSession($store, $id);
        $session->set('key', 'value');

        self::assertTrue($session->save());
    }

    public function testReadDataLoadsFromStoreOnFirstAccess(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn('{"persisted":"data"}');

        $session = new InitializedSession($store, Uuid::v4());

        self::assertSame('data', $session->get('persisted'));
    }

    public function testReadDataHandlesFalseFromStore(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);

        $session = new InitializedSession($store, Uuid::v4());

        self::assertSame([], $session->all());
    }

    public function testReadDataHandlesNonArrayJson(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn('"string"');

        $session = new InitializedSession($store, Uuid::v4());

        self::assertSame([], $session->all());
    }

    public function testHydrateReplacesData(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('old', 'value');
        $session->hydrate(['new' => 'data']);

        self::assertSame(['new' => 'data'], $session->all());
    }

    public function testJsonSerializeReturnsAllData(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn('{"a":"b"}');
        $session = new InitializedSession($store, Uuid::v4());

        self::assertSame(['a' => 'b'], $session->jsonSerialize());
    }

    public function testSetWithoutOverwritePreservesExistingValue(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $store->method('read')->willReturn(false);
        $session = new InitializedSession($store, Uuid::v4());

        $session->set('key', 'first');
        $session->set('key', 'second', false);

        self::assertSame('first', $session->get('key'));
    }
}
