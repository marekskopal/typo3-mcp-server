<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\SiteConfigurationResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use const JSON_THROW_ON_ERROR;

#[CoversClass(SiteConfigurationResource::class)]
final class SiteConfigurationResourceTest extends TestCase
{
    public function testExecuteReturnsSiteConfiguration(): void
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
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getBase')->willReturn(new Uri('https://example.com/'));
        $site->method('getAllLanguages')->willReturn([0 => $english, 1 => $german]);

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['main' => $site]);

        $resource = new SiteConfigurationResource($siteFinder);
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $result);
        self::assertSame('main', $result[0]['identifier']);
        self::assertSame(1, $result[0]['rootPageId']);
        self::assertSame('https://example.com/', $result[0]['base']);
        self::assertCount(2, $result[0]['languages']);
        self::assertSame(0, $result[0]['languages'][0]['languageId']);
        self::assertSame('English', $result[0]['languages'][0]['title']);
        self::assertSame(1, $result[0]['languages'][1]['languageId']);
        self::assertSame('German', $result[0]['languages'][1]['title']);
    }

    public function testExecuteReturnsEmptyArrayWhenNoSites(): void
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $resource = new SiteConfigurationResource($siteFinder);
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $result);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willThrowException(new \RuntimeException('Sites unavailable'));

        $resource = new SiteConfigurationResource($siteFinder);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sites unavailable');

        $resource->execute();
    }
}
