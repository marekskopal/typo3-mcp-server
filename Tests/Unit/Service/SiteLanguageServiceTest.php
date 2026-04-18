<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(SiteLanguageService::class)]
final class SiteLanguageServiceTest extends TestCase
{
    public function testGetLanguagesForPageReturnsMappedLanguages(): void
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
        $result = $service->getLanguagesForPage(1);

        self::assertCount(2, $result);
        self::assertSame(0, $result[0]['languageId']);
        self::assertSame('English', $result[0]['title']);
        self::assertSame('us', $result[0]['flagIdentifier']);
        self::assertTrue($result[0]['enabled']);
        self::assertSame('en-US', $result[0]['hreflang']);
        self::assertSame(1, $result[1]['languageId']);
        self::assertSame('German', $result[1]['title']);
    }

    public function testGetLanguagesForPageThrowsWhenSiteNotFound(): void
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('No site found'));

        $service = new SiteLanguageService($siteFinder);

        $this->expectException(SiteNotFoundException::class);
        $service->getLanguagesForPage(999);
    }
}
