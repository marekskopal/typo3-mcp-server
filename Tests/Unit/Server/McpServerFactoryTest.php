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
use MarekSkopal\MsMcpServer\Service\WorkspaceContextService;
use MarekSkopal\MsMcpServer\Tool\Cache\CacheClearTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCopyTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentGetTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentListTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentMoveTool;
use MarekSkopal\MsMcpServer\Tool\Content\ContentUpdateTool;
use MarekSkopal\MsMcpServer\Repository\DiscoveredTableRepository;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Redirect\RedirectToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Scheduler\SchedulerToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Workspace\WorkspaceToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryCreateTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryMoveTool;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryRenameTool;
use MarekSkopal\MsMcpServer\Tool\File\FileDeleteTool;
use MarekSkopal\MsMcpServer\Tool\File\FileGetInfoTool;
use MarekSkopal\MsMcpServer\Tool\File\FileListTool;
use MarekSkopal\MsMcpServer\Tool\File\FileMoveTool;
use MarekSkopal\MsMcpServer\Tool\File\FileSearchTool;
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
use MarekSkopal\MsMcpServer\Tool\Search\RecordCountTool;
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
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

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

        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturn(false);
        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    public function testCreateReturnsServerInstance(): void
    {
        $connectionPool = $this->createStub(ConnectionPool::class);
        $storageRepository = $this->createStub(StorageRepository::class);
        $siteFinder = $this->createStub(SiteFinder::class);
        $recordService = new RecordService($connectionPool, new WorkspaceContextService());
        $dataHandlerService = new DataHandlerService($this->createStub(SiteFinder::class));
        $fileService = new FileService($storageRepository, $connectionPool);
        $logger = new NullLogger();
        $tcaSchemaService = new TcaSchemaService();
        $siteLanguageService = new SiteLanguageService($siteFinder);
        $cacheService = new CacheService($this->createStub(CacheManager::class));
        $backendLayoutService = new BackendLayoutService($this->createStub(BackendLayoutView::class));

        $typo3Version = $this->createStub(Typo3Version::class);
        $typo3Version->method('getVersion')->willReturn('13.4.0');

        $tools = [
            new PagesListTool($recordService, $tcaSchemaService),
            new PagesGetTool($recordService, $tcaSchemaService),
            new PagesCreateTool($dataHandlerService, $tcaSchemaService),
            new PagesUpdateTool($dataHandlerService, $tcaSchemaService),
            new PagesDeleteTool($dataHandlerService),
            new PagesCopyTool($dataHandlerService),
            new PageTreeTool($recordService, $tcaSchemaService),
            new ContentListTool($recordService, $tcaSchemaService),
            new ContentGetTool($recordService, $tcaSchemaService),
            new ContentCreateTool($dataHandlerService, $tcaSchemaService),
            new ContentUpdateTool($dataHandlerService, $tcaSchemaService),
            new ContentDeleteTool($dataHandlerService),
            new ContentMoveTool($dataHandlerService),
            new ContentCopyTool($dataHandlerService),
            new FileListTool($fileService),
            new FileGetInfoTool($fileService),
            new FileUploadTool($fileService),
            new FileDeleteTool($fileService),
            new FileMoveTool($fileService),
            new FileRenameTool($fileService),
            new DirectoryCreateTool($fileService),
            new DirectoryDeleteTool($fileService),
            new DirectoryMoveTool($fileService),
            new DirectoryRenameTool($fileService),
            new FileReferenceAddTool($dataHandlerService, $tcaSchemaService),
            new FileReferenceListTool($recordService, $tcaSchemaService),
            new FileReferenceRemoveTool($dataHandlerService),
            new FileUploadFromUrlTool($fileService),
            new FileSearchTool($fileService),
            new TableSchemaTool($tcaSchemaService),
            new RecordSearchTool($recordService, $tcaSchemaService),
            new RecordCountTool($recordService, $tcaSchemaService),
            new SiteLanguagesTool($siteLanguageService),
            new RecordTranslateTool($dataHandlerService, $recordService, $tcaSchemaService),
            new CacheClearTool($cacheService),
        ];

        $resources = [
            new SystemInfoResource($typo3Version),
            new SiteConfigurationResource($siteFinder),
            new TcaTablesResource(),
            new BackendUserResource(),
            new TcaTableSchemaResource($tcaSchemaService),
            new BackendLayoutResource($backendLayoutService),
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

        $discoveredTableRepository = $this->createStub(DiscoveredTableRepository::class);
        $discoveredTableRepository->method('findEnabled')->willReturn([]);
        $dynamicToolRegistrar = new DynamicToolRegistrar($recordService, $dataHandlerService, $tcaSchemaService, $discoveredTableRepository, $logger);
        $redirectToolRegistrar = new RedirectToolRegistrar($recordService, $dataHandlerService, $logger);
        $schedulerToolRegistrar = new SchedulerToolRegistrar($recordService, $dataHandlerService, $logger);
        $workspaceToolRegistrar = new WorkspaceToolRegistrar($recordService, $dataHandlerService, $connectionPool, $logger);

        $auditLogger = $this->createStub(\MarekSkopal\MsMcpServer\Logging\AuditLogger::class);

        $factory = new McpServerFactory($container, $dynamicToolRegistrar, $redirectToolRegistrar, $schedulerToolRegistrar, $workspaceToolRegistrar, $logger, $auditLogger, $tools, $resources, $prompts);
        $server = $factory->create();

        self::assertInstanceOf(Server::class, $server);
    }

    /**
     * Ensures every PHP class in Tool/ (that isn't excluded in Services.yaml) has a #[McpTool] attribute.
     * Catches utility classes that would crash McpServerFactory at runtime if tagged as mcp.tool.
     */
    public function testAllToolClassesHaveMcpToolAttribute(): void
    {
        $toolDir = __DIR__ . '/../../../Classes/Tool';
        $excludedDirs = ['Result', 'Dynamic', 'Redirect', 'Scheduler', 'Workspace'];
        $excludedFiles = ['SearchConditionParser.php'];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($toolDir));
        $missing = [];

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($toolDir . '/', '', $file->getPathname());

            $skip = false;
            foreach ($excludedDirs as $dir) {
                if (str_starts_with($relativePath, $dir . '/')) {
                    $skip = true;

                    break;
                }
            }

            if ($skip || in_array($file->getFilename(), $excludedFiles, true)) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (!str_contains($content, '#[McpTool')) {
                $missing[] = $relativePath;
            }
        }

        self::assertSame([], $missing, 'Tool classes without #[McpTool] attribute (exclude in Services.yaml or add attribute): ' . implode(', ', $missing));
    }
}
