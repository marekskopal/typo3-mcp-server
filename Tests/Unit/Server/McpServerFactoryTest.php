<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use MarekSkopal\MsMcpServer\Prompt\AuditPageSeoPrompt;
use MarekSkopal\MsMcpServer\Prompt\SummarizePagePrompt;
use MarekSkopal\MsMcpServer\Prompt\TranslatePageContentPrompt;
use MarekSkopal\MsMcpServer\Resource\BackendLayoutResource;
use MarekSkopal\MsMcpServer\Resource\BackendUserResource;
use MarekSkopal\MsMcpServer\Resource\SiteConfigurationResource;
use MarekSkopal\MsMcpServer\Resource\SystemInfoResource;
use MarekSkopal\MsMcpServer\Resource\TcaTableSchemaResource;
use MarekSkopal\MsMcpServer\Resource\TcaTablesResource;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use MarekSkopal\MsMcpServer\Service\BackendLayoutService;
use MarekSkopal\MsMcpServer\Service\CacheService;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Cache\CacheClearTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCopyTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentGetTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentListTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentMoveTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentUpdateTool;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryCreateTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryMoveTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryRenameTool;
use MarekSkopal\MsMcpServer\Tool\File\FileDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\FileGetInfoTool;
use MarekSkopal\MsMcpServer\Tool\File\FileListTool;
use MarekSkopal\MsMcpServer\Tool\File\FileMoveTool;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceAddTool;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceListTool;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceRemoveTool;
use MarekSkopal\MsMcpServer\Tool\File\FileRenameTool;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadFromUrlTool;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadTool;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesCopyTool;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(McpServerFactory::class)]
final class McpServerFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/tmp/typo3-test',
            '/tmp/typo3-test/public',
            '/tmp/typo3-test/var',
            '/tmp/typo3-test/config',
            '/tmp/typo3-test/index.php',
            'UNIX',
        );
    }

    public function testCreateReturnsServerInstance(): void
    {
        $connectionPool = $this->createStub(ConnectionPool::class);
        $storageRepository = $this->createStub(StorageRepository::class);
        $siteFinder = $this->createStub(SiteFinder::class);
        $recordService = new RecordService($connectionPool);
        $dataHandlerService = new DataHandlerService($this->createStub(SiteFinder::class));
        $fileService = new FileService($storageRepository);
        $logger = new NullLogger();
        $tcaSchemaService = new TcaSchemaService();
        $siteLanguageService = new SiteLanguageService($siteFinder);
        $cacheService = new CacheService($this->createStub(CacheManager::class));
        $backendLayoutService = new BackendLayoutService($this->createStub(BackendLayoutView::class));

        $typo3Version = $this->createStub(Typo3Version::class);
        $typo3Version->method('getVersion')->willReturn('13.4.0');

        $tools = [
            new PagesListTool($recordService, $tcaSchemaService, $logger),
            new PagesGetTool($recordService, $tcaSchemaService, $logger),
            new PagesCreateTool($dataHandlerService, $tcaSchemaService, $logger),
            new PagesUpdateTool($dataHandlerService, $tcaSchemaService, $logger),
            new PagesDeleteTool($dataHandlerService, $logger),
            new PagesCopyTool($dataHandlerService, $logger),
            new PageTreeTool($recordService, $tcaSchemaService, $logger),
            new ContentListTool($recordService, $tcaSchemaService, $logger),
            new ContentGetTool($recordService, $tcaSchemaService, $logger),
            new ContentCreateTool($dataHandlerService, $tcaSchemaService, $logger),
            new ContentUpdateTool($dataHandlerService, $tcaSchemaService, $logger),
            new ContentDeleteTool($dataHandlerService, $logger),
            new ContentMoveTool($dataHandlerService, $logger),
            new ContentCopyTool($dataHandlerService, $logger),
            new FileListTool($fileService, $logger),
            new FileGetInfoTool($fileService, $logger),
            new FileUploadTool($fileService, $logger),
            new FileDeleteTool($fileService, $logger),
            new FileMoveTool($fileService, $logger),
            new FileRenameTool($fileService, $logger),
            new DirectoryCreateTool($fileService, $logger),
            new DirectoryDeleteTool($fileService, $logger),
            new DirectoryMoveTool($fileService, $logger),
            new DirectoryRenameTool($fileService, $logger),
            new FileReferenceAddTool($dataHandlerService, $tcaSchemaService, $logger),
            new FileReferenceListTool($recordService, $tcaSchemaService, $logger),
            new FileReferenceRemoveTool($dataHandlerService, $logger),
            new FileUploadFromUrlTool($fileService, $logger),
            new TableSchemaTool($tcaSchemaService, $logger),
            new RecordSearchTool($recordService, $tcaSchemaService, $logger),
            new SiteLanguagesTool($siteLanguageService, $logger),
            new RecordTranslateTool($dataHandlerService, $recordService, $tcaSchemaService, $logger),
            new CacheClearTool($cacheService, $logger),
        ];

        $resources = [
            new SystemInfoResource($typo3Version, $logger),
            new SiteConfigurationResource($siteFinder, $logger),
            new TcaTablesResource($logger),
            new BackendUserResource($logger),
            new TcaTableSchemaResource($tcaSchemaService, $logger),
            new BackendLayoutResource($backendLayoutService, $logger),
        ];

        $prompts = [
            new TranslatePageContentPrompt($siteLanguageService),
            new AuditPageSeoPrompt(),
            new SummarizePagePrompt(),
        ];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($tools, $resources, $prompts): object {
                foreach ([...$tools, ...$resources, ...$prompts] as $instance) {
                    if ($instance::class === $id) {
                        return $instance;
                    }
                }

                throw new \RuntimeException('Unknown service: ' . $id);
            },
        );

        $dynamicToolRegistrar = new DynamicToolRegistrar($recordService, $dataHandlerService, $tcaSchemaService, $logger);

        $factory = new McpServerFactory($container, $dynamicToolRegistrar, $tools, $resources, $prompts);
        $server = $factory->create();

        self::assertInstanceOf(Server::class, $server);
    }
}
