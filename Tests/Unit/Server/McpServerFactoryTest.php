<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use Mcp\Server;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use MarekSkopal\MsMcpServer\Service\BackendLayoutService;
use MarekSkopal\MsMcpServer\Service\CacheService;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Information\Typo3Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
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

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($recordService, $dataHandlerService, $fileService, $tcaSchemaService, $siteLanguageService, $cacheService, $backendLayoutService, $siteFinder, $typo3Version, $logger): object {
                return match (true) {
                    str_contains($id, 'RecordService') => $recordService,
                    str_contains($id, 'DataHandlerService') => $dataHandlerService,
                    str_contains($id, 'FileService') => $fileService,
                    str_contains($id, 'TcaSchemaService') => $tcaSchemaService,
                    str_contains($id, 'SiteLanguageService') => $siteLanguageService,
                    str_contains($id, 'LoggerInterface') || str_contains($id, 'Logger') => $logger,
                    str_contains($id, 'TableSchemaTool') => new ($id)($tcaSchemaService, $logger),
                    str_contains($id, 'RecordTranslateTool') => new ($id)($dataHandlerService, $tcaSchemaService, $logger),
                    str_contains($id, 'SiteLanguagesTool') => new ($id)($siteLanguageService, $logger),
                    str_contains($id, 'CacheClearTool') => new ($id)($cacheService, $logger),
                    str_contains($id, 'TranslatePageContentPrompt') => new ($id)($siteLanguageService),
                    str_contains($id, 'Prompt') => new ($id)(),
                    str_contains($id, 'SystemInfoResource') => new ($id)($typo3Version, $logger),
                    str_contains($id, 'SiteConfigurationResource') => new ($id)($siteFinder, $logger),
                    str_contains($id, 'TcaTableSchemaResource') => new ($id)($tcaSchemaService, $logger),
                    str_contains($id, 'BackendLayoutResource') => new ($id)($backendLayoutService, $logger),
                    str_contains($id, 'BackendUserResource') || str_contains($id, 'TcaTablesResource') => new ($id)($logger),
                    default => new ($id)($recordService, $logger),
                };
            },
        );

        $dynamicToolRegistrar = new DynamicToolRegistrar($recordService, $dataHandlerService, new TcaSchemaService(), $logger);

        $factory = new McpServerFactory($container, $dynamicToolRegistrar);
        $server = $factory->create();

        self::assertInstanceOf(Server::class, $server);
    }
}
