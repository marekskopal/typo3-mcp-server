<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentGetTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentListTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentUpdateTool;
use MarekSkopal\MsMcpServer\Tool\News\NewsCreateTool;
use MarekSkopal\MsMcpServer\Tool\News\NewsDeleteTool;
use MarekSkopal\MsMcpServer\Tool\News\NewsGetTool;
use MarekSkopal\MsMcpServer\Tool\News\NewsListTool;
use MarekSkopal\MsMcpServer\Tool\News\NewsUpdateTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesCreateTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesGetTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesListTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesUpdateTool;
use Mcp\Server;
use Psr\Container\ContainerInterface;

readonly class McpServerFactory
{
    private const array TOOLS = [
        [PagesListTool::class, 'execute', 'pages_list'],
        [PagesGetTool::class, 'execute', 'pages_get'],
        [PagesCreateTool::class, 'execute', 'pages_create'],
        [PagesUpdateTool::class, 'execute', 'pages_update'],
        [PagesDeleteTool::class, 'execute', 'pages_delete'],
        [ContentListTool::class, 'execute', 'content_list'],
        [ContentGetTool::class, 'execute', 'content_get'],
        [ContentCreateTool::class, 'execute', 'content_create'],
        [ContentUpdateTool::class, 'execute', 'content_update'],
        [ContentDeleteTool::class, 'execute', 'content_delete'],
        [NewsListTool::class, 'execute', 'news_list'],
        [NewsGetTool::class, 'execute', 'news_get'],
        [NewsCreateTool::class, 'execute', 'news_create'],
        [NewsUpdateTool::class, 'execute', 'news_update'],
        [NewsDeleteTool::class, 'execute', 'news_delete'],
    ];

    public function __construct(private ContainerInterface $container)
    {
    }

    public function create(): Server
    {
        $builder = Server::builder()
            ->setServerInfo('TYPO3 MCP Server', '1.0.0')
            ->setContainer($this->container);

        foreach (self::TOOLS as [$class, $method, $name]) {
            $builder->addTool([$class, $method], $name);
        }

        return $builder->build();
    }
}
