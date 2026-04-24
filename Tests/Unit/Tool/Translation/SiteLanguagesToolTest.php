<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use MarekSkopal\MsMcpServer\Tool\Translation\SiteLanguagesTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use const JSON_THROW_ON_ERROR;

#[CoversClass(SiteLanguagesTool::class)]
final class SiteLanguagesToolTest extends TestCase
{
    public function testExecuteReturnsLanguagesAsJson(): void
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

        $service = new SiteLanguageService($siteFinder);
        $tool = new SiteLanguagesTool($service);
        $result = json_decode($tool->execute(1), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $result);
        self::assertSame(0, $result[0]['languageId']);
        self::assertSame('English', $result[0]['title']);
        self::assertSame(1, $result[1]['languageId']);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('Site not found'));

        $service = new SiteLanguageService($siteFinder);
        $tool = new SiteLanguagesTool($service);

        $this->expectException(SiteNotFoundException::class);
        $this->expectExceptionMessage('Site not found');

        $tool->execute(999);
    }
}
