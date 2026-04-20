<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use MarekSkopal\MsMcpServer\Server\InitializedSession;
use MarekSkopal\MsMcpServer\Server\InitializedSessionFactory;
use Mcp\Server\Session\SessionStoreInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(InitializedSessionFactory::class)]
final class InitializedSessionFactoryTest extends TestCase
{
    public function testCreateReturnsInitializedSession(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $factory = new InitializedSessionFactory();

        $session = $factory->create($store);

        self::assertInstanceOf(InitializedSession::class, $session);
        self::assertSame($store, $session->getStore());
    }

    public function testCreateWithIdReturnsSessionWithGivenId(): void
    {
        $store = $this->createStub(SessionStoreInterface::class);
        $id = Uuid::v4();
        $factory = new InitializedSessionFactory();

        $session = $factory->createWithId($id, $store);

        self::assertInstanceOf(InitializedSession::class, $session);
        self::assertSame($id, $session->getId());
        self::assertSame($store, $session->getStore());
    }
}
