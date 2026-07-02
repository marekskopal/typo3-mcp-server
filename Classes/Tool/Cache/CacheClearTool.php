<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Cache;

use MarekSkopal\MsMcpServer\Service\CacheService;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Tool\Result\CacheClearedResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Capability\Attribute\McpTool;

readonly class CacheClearTool
{
    public function __construct(private CacheService $cacheService, private PermissionService $permissionService)
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

        // Flushing every cache (including system caches) is an admin-only action in TYPO3 core and
        // is a cheap DoS lever on production, so restrict the "all" scope to administrators.
        if ($scope === 'all' && !$this->permissionService->isAdmin()) {
            return new ErrorResult('Clearing all caches requires administrator privileges.');
        }

        match ($scope) {
            'pages' => $this->cacheService->flushPageCaches(),
            'all' => $this->cacheService->flushAllCaches(),
            'page' => $this->cacheService->flushPageCache($pageId),
        };

        return new CacheClearedResult($scope);
    }
}
