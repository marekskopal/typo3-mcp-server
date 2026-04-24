<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Cache;

use MarekSkopal\MsMcpServer\Service\CacheService;
use MarekSkopal\MsMcpServer\Tool\Result\CacheClearedResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Capability\Attribute\McpTool;

readonly class CacheClearTool
{
    public function __construct(private CacheService $cacheService)
    {
    }

    #[McpTool(
        name: 'cache_clear',
        description: 'Clear TYPO3 caches. Scope: "pages" (default) clears page and content caches,'
            . ' "all" clears all caches including system caches, "page" clears cache for a single page (requires pageId).',
    )]
    public function execute(string $scope = 'pages', int $pageId = 0): CacheClearedResult|ErrorResult
    {
        if (!in_array($scope, ['pages', 'all', 'page'], true)) {
            return new ErrorResult('Invalid scope: ' . $scope, ['validScopes' => ['pages', 'all', 'page']]);
        }

        if ($scope === 'page' && $pageId === 0) {
            return new ErrorResult('pageId is required when scope is "page"');
        }

        match ($scope) {
            'pages' => $this->cacheService->flushPageCaches(),
            'all' => $this->cacheService->flushAllCaches(),
            'page' => $this->cacheService->flushPageCache($pageId),
        };

        return new CacheClearedResult($scope);
    }
}
