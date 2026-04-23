<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Prompt;

use MarekSkopal\MsMcpServer\Prompt\TranslatePageContentPrompt;
use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(TranslatePageContentPrompt::class)]
final class TranslatePageContentPromptTest extends TestCase
{
    public function testExecuteReturnsUserMessageWithLanguages(): void
    {
        $prompt = new TranslatePageContentPrompt($this->createSiteLanguageService());
        $result = $prompt->execute(42);

        self::assertArrayHasKey('user', $result);
        self::assertStringContainsString('42', $result['user']);
        self::assertStringContainsString('English', $result['user']);
        self::assertStringContainsString('German', $result['user']);
        self::assertStringContainsString('record_translate', $result['user']);
        self::assertStringContainsString('content_list', $result['user']);
    }

    public function testExecuteWithTargetLanguageIncludesSpecificInstruction(): void
    {
        $prompt = new TranslatePageContentPrompt($this->createSiteLanguageService());
        $result = $prompt->execute(42, 1);

        self::assertStringContainsString('Translate to language ID 1 only', $result['user']);
    }

    public function testExecuteWithoutTargetLanguageTranslatesAll(): void
    {
        $prompt = new TranslatePageContentPrompt($this->createSiteLanguageService());
        $result = $prompt->execute(42);

        self::assertStringContainsString('all enabled non-default languages', $result['user']);
    }

    private function createSiteLanguageService(): SiteLanguageService
    {
        $english = new SiteLanguage(0, 'en_US.UTF-8', new Uri('/'), [
            'title' => 'English',
            'flag' => 'us',
            'enabled' => true,
            'hreflang' => 'en-US',
        ]);
        $german = new SiteLanguage(1, 'de_DE.UTF-8', new Uri('/de/'), [
            'title' => 'German',
            'flag' => 'de',
            'enabled' => true,
            'hreflang' => 'de-DE',
        ]);

        $site = $this->createStub(Site::class);
        $site->method('getAllLanguages')->willReturn([0 => $english, 1 => $german]);

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        return new SiteLanguageService($siteFinder);
    }
}
