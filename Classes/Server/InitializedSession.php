<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Workaround for mcp/sdk Session::$data uninitialized property bug.
 * Session::save() accesses $data directly without calling readData() first,
 * which crashes when only notifications (not requests) are processed.
 *
 * @see https://github.com/php-mcp/sdk/issues/XXX
 */
final class InitializedSession extends Session
{
    public function __construct(SessionStoreInterface $store, Uuid $id)
    {
        parent::__construct($store, $id);

        $this->hydrate($this->all());
    }
}
