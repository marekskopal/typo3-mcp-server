<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentGetTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentListTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentUpdateTool;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryCreateTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\FileDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\FileGetInfoTool;
use MarekSkopal\MsMcpServer\Tool\File\FileListTool;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesCreateTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesGetTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesListTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesUpdateTool;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\Environment;

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
        [FileListTool::class, 'execute', 'file_list'],
        [FileGetInfoTool::class, 'execute', 'file_get_info'],
        [FileUploadTool::class, 'execute', 'file_upload'],
        [FileDeleteTool::class, 'execute', 'file_delete'],
        [DirectoryCreateTool::class, 'execute', 'directory_create'],
        [DirectoryDeleteTool::class, 'execute', 'directory_delete'],
    ];

    public function __construct(private ContainerInterface $container, private DynamicToolRegistrar $dynamicToolRegistrar,)
    {
    }

    public function create(): Server
    {
        $sessionDir = Environment::getVarPath() . '/mcp-sessions';
        $sessionStore = new FileSessionStore($sessionDir);

        $builder = Server::builder()
            ->setServerInfo('TYPO3 MCP Server', '1.0.0')
            ->setContainer($this->container)
            ->setSession($sessionStore, new InitializedSessionFactory());

        foreach (self::TOOLS as [$class, $method, $name]) {
            $builder->addTool([$class, $method], $name);
        }

        $this->dynamicToolRegistrar->register($builder);

        return $builder->build();
    }
}
