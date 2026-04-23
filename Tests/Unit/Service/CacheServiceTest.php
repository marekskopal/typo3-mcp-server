<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\CacheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

#[CoversClass(CacheService::class)]
final class CacheServiceTest extends TestCase
{
    public function testFlushPageCachesFlushesPageGroup(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::once())
            ->method('flushCachesInGroup')
            ->with('pages');

        $service = new CacheService($cacheManager);
        $service->flushPageCaches();
    }

    public function testFlushAllCachesFlushesEverything(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::once())
            ->method('flushCaches');

        $service = new CacheService($cacheManager);
        $service->flushAllCaches();
    }

    public function testFlushPageCacheFlushesSpecificPageByTag(): void
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->expects(self::once())
            ->method('flushCachesInGroupByTag')
            ->with('pages', 'pageId_42');

        $service = new CacheService($cacheManager);
        $service->flushPageCache(42);
    }
}
