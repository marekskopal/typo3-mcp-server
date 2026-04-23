<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Resource\BackendUserResource;
use MarekSkopal\MsMcpServer\Resource\SiteConfigurationResource;
use MarekSkopal\MsMcpServer\Resource\SystemInfoResource;
use MarekSkopal\MsMcpServer\Resource\TcaTableSchemaResource;
use MarekSkopal\MsMcpServer\Resource\TcaTablesResource;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentGetTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentListTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentMoveTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentUpdateTool;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryCreateTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\FileDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\FileGetInfoTool;
use MarekSkopal\MsMcpServer\Tool\File\FileListTool;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceAddTool;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadFromUrlTool;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesCreateTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesGetTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesListTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesUpdateTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PageTreeTool;
use MarekSkopal\MsMcpServer\Tool\Schema\TableSchemaTool;
use MarekSkopal\MsMcpServer\Tool\Search\RecordSearchTool;
use MarekSkopal\MsMcpServer\Tool\Translation\RecordTranslateTool;
use MarekSkopal\MsMcpServer\Tool\Translation\SiteLanguagesTool;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\Environment;

readonly class McpServerFactory
{
    public const string VERSION = '0.3.0';

    private const array TOOLS = [
        [PagesListTool::class, 'execute', 'pages_list'],
        [PagesGetTool::class, 'execute', 'pages_get'],
        [PagesCreateTool::class, 'execute', 'pages_create'],
        [PagesUpdateTool::class, 'execute', 'pages_update'],
        [PagesDeleteTool::class, 'execute', 'pages_delete'],
        [PageTreeTool::class, 'execute', 'pages_tree'],
        [ContentListTool::class, 'execute', 'content_list'],
        [ContentGetTool::class, 'execute', 'content_get'],
        [ContentCreateTool::class, 'execute', 'content_create'],
        [ContentUpdateTool::class, 'execute', 'content_update'],
        [ContentDeleteTool::class, 'execute', 'content_delete'],
        [ContentMoveTool::class, 'execute', 'content_move'],
        [FileListTool::class, 'execute', 'file_list'],
        [FileGetInfoTool::class, 'execute', 'file_get_info'],
        [FileUploadTool::class, 'execute', 'file_upload'],
        [FileDeleteTool::class, 'execute', 'file_delete'],
        [DirectoryCreateTool::class, 'execute', 'directory_create'],
        [DirectoryDeleteTool::class, 'execute', 'directory_delete'],
        [FileReferenceAddTool::class, 'execute', 'file_reference_add'],
        [FileUploadFromUrlTool::class, 'execute', 'file_upload_from_url'],
        [TableSchemaTool::class, 'execute', 'table_schema'],
        [RecordSearchTool::class, 'execute', 'record_search'],
        [SiteLanguagesTool::class, 'execute', 'site_languages'],
        [RecordTranslateTool::class, 'execute', 'record_translate'],
    ];

    private const array RESOURCES = [
        [SystemInfoResource::class, 'execute', 'typo3://system/info'],
        [SiteConfigurationResource::class, 'execute', 'typo3://sites'],
        [TcaTablesResource::class, 'execute', 'typo3://schema/tables'],
        [BackendUserResource::class, 'execute', 'typo3://user/me'],
    ];

    private const array RESOURCE_TEMPLATES = [
        [TcaTableSchemaResource::class, 'execute', 'typo3://schema/tables/{tableName}'],
    ];

    public function __construct(private ContainerInterface $container, private DynamicToolRegistrar $dynamicToolRegistrar,)
    {
    }

    public function create(): Server
    {
        $sessionDir = Environment::getVarPath() . '/mcp-sessions';
        $sessionStore = new FileSessionStore($sessionDir);

        $builder = Server::builder()
            ->setServerInfo('TYPO3 MCP Server', self::VERSION)
            ->setContainer($this->container)
            ->setSession($sessionStore, new InitializedSessionFactory());

        foreach (self::TOOLS as [$class, $method, $name]) {
            $builder->addTool([$class, $method], $name);
        }

        $this->dynamicToolRegistrar->register($builder);

        foreach (self::RESOURCES as [$class, $method, $uri]) {
            $builder->addResource([$class, $method], $uri);
        }

        foreach (self::RESOURCE_TEMPLATES as [$class, $method, $uriTemplate]) {
            $builder->addResourceTemplate([$class, $method], $uriTemplate);
        }

        return $builder->build();
    }
}
