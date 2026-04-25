<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Prompt;

use MarekSkopal\MsMcpServer\Prompt\CheckTranslationStatusPrompt;
use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(CheckTranslationStatusPrompt::class)]
final class CheckTranslationStatusPromptTest extends TestCase
{
    public function testExecuteReturnsUserMessageWithLanguagesAndTools(): void
    {
        $prompt = new CheckTranslationStatusPrompt($this->createSiteLanguageService());
        $result = $prompt->execute(1);

        self::assertArrayHasKey('user', $result);
        self::assertStringContainsString('1', $result['user']);
        self::assertStringContainsString('German', $result['user']);
        self::assertStringContainsString('pages_tree', $result['user']);
        self::assertStringContainsString('content_list', $result['user']);
        self::assertStringContainsString('coverage', $result['user']);
    }

    public function testExecuteExcludesDefaultLanguage(): void
    {
        $prompt = new CheckTranslationStatusPrompt($this->createSiteLanguageService());
        $result = $prompt->execute(1);

        self::assertStringNotContainsString('ID 0:', $result['user']);
        self::assertStringContainsString('ID 1:', $result['user']);
    }

    public function testExecuteUsesCustomDepth(): void
    {
        $prompt = new CheckTranslationStatusPrompt($this->createSiteLanguageService());
        $result = $prompt->execute(1, 5);

        self::assertStringContainsString('depth=5', $result['user']);
    }

    private function createSiteLanguageService(): SiteLanguageService
    {
        $english = new SiteLanguage(0, 'en_US.UTF-8', new Uri('/'), [
            'title' => 'English',
            'enabled' => true,
        ]);
        $german = new SiteLanguage(1, 'de_DE.UTF-8', new Uri('/de/'), [
            'title' => 'German',
            'enabled' => true,
        ]);

        $site = $this->createStub(Site::class);
        $site->method('getAllLanguages')->willReturn([0 => $english, 1 => $german]);

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        return new SiteLanguageService($siteFinder);
    }
}
