<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

final readonly class InitializedSessionFactory implements SessionFactoryInterface
{
    public function create(SessionStoreInterface $store): SessionInterface
    {
        return new InitializedSession($store, Uuid::v4());
    }

    public function createWithId(Uuid $id, SessionStoreInterface $store): SessionInterface
    {
        return new InitializedSession($store, $id);
    }
}
