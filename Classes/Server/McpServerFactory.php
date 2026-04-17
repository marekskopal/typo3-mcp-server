<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\Environment;

readonly class McpServerFactory
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function create(): Server
    {
        $sessionDir = Environment::getVarPath() . '/mcp-sessions';
        $sessionStore = new FileSessionStore($sessionDir);

        $builder = Server::builder()
            ->setServerInfo('TYPO3 MCP Server', '1.0.0')
            ->setContainer($this->container)
            ->setSession($sessionStore, new InitializedSessionFactory())
            ->setDiscovery(dirname(__DIR__, 2), ['Classes/Tool']);

        return $builder->build();
    }
}
