<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\ExtensionTableDiscoveryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

#[CoversClass(ExtensionTableDiscoveryService::class)]
final class ExtensionTableDiscoveryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables'], $GLOBALS['LANG']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA'], $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables'], $GLOBALS['LANG']);
    }

    public function testDiscoverTablesReturnsExtensionTables(): void
    {
        $GLOBALS['TCA'] = [
            'tx_news_domain_model_news' => ['ctrl' => ['title' => 'News']],
            'tx_blog_domain_model_post' => ['ctrl' => ['title' => 'Blog Post']],
            'pages' => ['ctrl' => ['title' => 'Pages']],
            'tt_content' => ['ctrl' => ['title' => 'Content']],
            'sys_file' => ['ctrl' => ['title' => 'File']],
        ];

        $service = $this->createService();
        $result = $service->discoverTables();

        self::assertArrayHasKey('tx_news_domain_model_news', $result);
        self::assertArrayHasKey('tx_blog_domain_model_post', $result);
        self::assertArrayNotHasKey('pages', $result);
        self::assertArrayNotHasKey('tt_content', $result);
        self::assertArrayNotHasKey('sys_file', $result);
    }

    public function testDiscoverTablesExcludesExtconfTables(): void
    {
        $GLOBALS['TCA'] = [
            'tx_news_domain_model_news' => ['ctrl' => ['title' => 'News']],
            'tx_blog_domain_model_post' => ['ctrl' => ['title' => 'Blog Post']],
        ];
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables'] = [
            'tx_news_domain_model_news' => ['label' => 'News', 'prefix' => 'news'],
        ];

        $service = $this->createService();
        $result = $service->discoverTables();

        self::assertArrayNotHasKey('tx_news_domain_model_news', $result);
        self::assertArrayHasKey('tx_blog_domain_model_post', $result);
    }

    public function testDiscoverTablesExcludesSystemTables(): void
    {
        $GLOBALS['TCA'] = [
            'sys_file' => ['ctrl' => ['title' => 'File']],
            'be_users' => ['ctrl' => ['title' => 'Backend Users']],
            'fe_users' => ['ctrl' => ['title' => 'Frontend Users']],
            'cache_hash' => ['ctrl' => ['title' => 'Cache']],
            'tx_msmcpserver_oauth_client' => ['ctrl' => ['title' => 'OAuth Client']],
        ];

        $service = $this->createService();
        $result = $service->discoverTables();

        self::assertSame([], $result);
    }

    public function testDiscoverTablesReturnsEmptyWhenNoTca(): void
    {
        $service = $this->createService();
        $result = $service->discoverTables();

        self::assertSame([], $result);
    }

    #[DataProvider('prefixProvider')]
    public function testGeneratePrefix(string $tableName, string $expectedPrefix): void
    {
        $service = $this->createService();

        self::assertSame($expectedPrefix, $service->generatePrefix($tableName));
    }

    /** @return array<string, array{string, string}> */
    public static function prefixProvider(): array
    {
        return [
            'same ext key and model name' => ['tx_news_domain_model_news', 'news'],
            'different ext key and model name' => ['tx_blog_domain_model_post', 'blog_post'],
            'different ext key and model name 2' => ['tx_news_domain_model_tag', 'news_tag'],
            'no domain model convention' => ['tx_myext_table', 'myext_table'],
            'no tx prefix' => ['custom_table', 'custom_table'],
            'complex model name' => ['tx_powermail_domain_model_form', 'powermail_form'],
        ];
    }

    public function testGenerateLabelUsesPlainTcaTitle(): void
    {
        $GLOBALS['TCA']['tx_news_domain_model_news'] = ['ctrl' => ['title' => 'News']];

        $service = $this->createService();

        self::assertSame('News', $service->generateLabel('tx_news_domain_model_news'));
    }

    public function testGenerateLabelFallsBackToHumanizedName(): void
    {
        $GLOBALS['TCA']['tx_blog_domain_model_post'] = ['ctrl' => []];

        $service = $this->createService();

        self::assertSame('Blog Post', $service->generateLabel('tx_blog_domain_model_post'));
    }

    public function testGenerateLabelHumanizesNameWithoutDomainModel(): void
    {
        $GLOBALS['TCA']['tx_myext_table'] = ['ctrl' => []];

        $service = $this->createService();

        self::assertSame('Myext Table', $service->generateLabel('tx_myext_table'));
    }

    public function testGenerateLabelResolvesLllReference(): void
    {
        $GLOBALS['TCA']['tx_news_domain_model_news'] = [
            'ctrl' => ['title' => 'LLL:EXT:news/Resources/Private/Language/locallang_db.xlf:tx_news_domain_model_news'],
        ];

        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturn('News Article');
        $GLOBALS['LANG'] = $languageService;

        $service = $this->createService();

        self::assertSame('News Article', $service->generateLabel('tx_news_domain_model_news'));
    }

    public function testGenerateLabelFallsBackWhenLllResolutionFails(): void
    {
        $lllKey = 'LLL:EXT:news/Resources/Private/Language/locallang_db.xlf:tx_news_domain_model_news';
        $GLOBALS['TCA']['tx_news_domain_model_news'] = [
            'ctrl' => ['title' => $lllKey],
        ];

        // sL returns the same string when resolution fails
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturn($lllKey);
        $GLOBALS['LANG'] = $languageService;

        $service = $this->createService();

        self::assertSame('News News', $service->generateLabel('tx_news_domain_model_news'));
    }

    public function testDiscoverTablesGeneratesCorrectLabelAndPrefix(): void
    {
        $GLOBALS['TCA'] = [
            'tx_news_domain_model_news' => ['ctrl' => ['title' => 'News']],
        ];

        $service = $this->createService();
        $result = $service->discoverTables();

        self::assertSame('News', $result['tx_news_domain_model_news']['label']);
        self::assertSame('news', $result['tx_news_domain_model_news']['prefix']);
    }

    private function createService(): ExtensionTableDiscoveryService
    {
        $languageService = $this->createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);

        $languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
        $languageServiceFactory->method('create')->willReturn($languageService);

        return new ExtensionTableDiscoveryService($languageServiceFactory);
    }
}
