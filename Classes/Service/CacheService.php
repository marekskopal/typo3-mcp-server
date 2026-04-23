<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Cache\CacheManager;

readonly class CacheService
{
    public function __construct(private CacheManager $cacheManager)
    {
    }

    public function flushPageCaches(): void
    {
        $this->cacheManager->flushCachesInGroup('pages');
    }

    public function flushAllCaches(): void
    {
        $this->cacheManager->flushCaches();
    }

    public function flushPageCache(int $pageId): void
    {
        $this->cacheManager->flushCachesInGroupByTag('pages', 'pageId_' . $pageId);
    }
}
