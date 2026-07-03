<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\PagePermissionRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class PermissionService
{
    private const int WEBMOUNT_DEPTH = 99;

    /** @return array{table: string, canSelect: bool, canModify: bool} */
    public function checkTableAccess(string $table): array
    {
        $backendUser = $this->getBackendUser();

        return [
            'table' => $table,
            'canSelect' => $backendUser->check('tables_select', $table),
            'canModify' => $backendUser->check('tables_modify', $table),
        ];
    }

    /**
     * @param array<string, mixed> $pageRow
     * @return array{pageId: int, canShow: bool, canEdit: bool, canDelete: bool, canCreateSubpages: bool, canEditContent: bool, permissionBitmask: int}
     */
    public function checkPageAccess(array $pageRow): array
    {
        $backendUser = $this->getBackendUser();

        /** @var int $perms */
        $perms = $backendUser->calcPerms($pageRow);

        $uid = $pageRow['uid'] ?? 0;

        return [
            'pageId' => is_int($uid) ? $uid : (int) (is_string($uid) ? $uid : 0),
            'canShow' => ($perms & Permission::PAGE_SHOW) === Permission::PAGE_SHOW,
            'canEdit' => ($perms & Permission::PAGE_EDIT) === Permission::PAGE_EDIT,
            'canDelete' => ($perms & Permission::PAGE_DELETE) === Permission::PAGE_DELETE,
            'canCreateSubpages' => ($perms & Permission::PAGE_NEW) === Permission::PAGE_NEW,
            'canEditContent' => ($perms & Permission::CONTENT_EDIT) === Permission::CONTENT_EDIT,
            'permissionBitmask' => $perms,
        ];
    }

    /** @return array{isAdmin: bool, tablesSelect: list<string>, tablesModify: list<string>, allowedLanguages: list<int>, filePermissions: array<string, bool>, webmounts: list<int>, filemounts: list<int>} */
    public function getPermissionSummary(): array
    {
        $backendUser = $this->getBackendUser();

        // @phpstan-ignore property.internal
        $groupData = $backendUser->groupData;

        /** @var array<string, bool> $filePermissions */
        $filePermissions = $backendUser->getFilePermissions();

        return [
            'isAdmin' => $backendUser->isAdmin(),
            'tablesSelect' => $this->parseCommaSeparatedList($this->getGroupDataString($groupData, 'tables_select')),
            'tablesModify' => $this->parseCommaSeparatedList($this->getGroupDataString($groupData, 'tables_modify')),
            'allowedLanguages' => $this->parseIntList($this->getGroupDataString($groupData, 'allowed_languages')),
            'filePermissions' => $filePermissions,
            'webmounts' => $this->parseIntList($this->getGroupDataString($groupData, 'webmounts')),
            'filemounts' => $this->parseIntList($this->getGroupDataString($groupData, 'filemounts')),
        ];
    }

    public function checkLanguageAccess(int $languageId): bool
    {
        return $this->getBackendUser()->checkLanguageAccess($languageId);
    }

    /**
     * Whether the current backend user may read records of the given table.
     * Admins always pass; for everyone else this honours the `tables_select` grant.
     */
    public function canSelectTable(string $table): bool
    {
        return $this->getBackendUser()->check('tables_select', $table);
    }

    public function isAdmin(): bool
    {
        return $this->getBackendUser()->isAdmin();
    }

    /**
     * The current backend user as a UserAspect, for feeding into permission-aware query
     * restrictions such as PagePermissionRestriction.
     */
    public function getUserAspect(): UserAspect
    {
        return new UserAspect($this->getBackendUser());
    }

    /**
     * All page uids reachable through the user's webmounts — the mounts themselves plus every
     * descendant page the user may show — or null for admins (unrestricted). This mirrors how
     * core's live search confines non-admin results (DatabaseRecordProvider::getPageIdList()).
     * The expansion walks the page tree, so the result is kept in the runtime cache per user.
     *
     * @return list<int>|null
     */
    public function getWebmountPageIds(): ?array
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return null;
        }

        $cacheKey = 'msmcpserver-webmount-pages-' . ($backendUser->getUserId() ?? 0);

        $cache = $this->getRuntimeCache();
        $cached = $cache?->get($cacheKey);
        if (is_array($cached)) {
            /** @var list<int> $cached */
            return $cached;
        }

        $pageIds = $this->expandWebmounts(array_map(intval(...), $backendUser->getWebmounts()));

        $cache?->set($cacheKey, $pageIds);

        return $pageIds;
    }

    /**
     * Breadth-first expansion of the mount pages to all descendants the user may show. A page the
     * user cannot show is pruned together with its whole subtree, mirroring the backend page tree.
     * The mounts themselves are always included (as in core's live search page-id list).
     *
     * @param list<int> $mounts
     * @return list<int>
     */
    private function expandWebmounts(array $mounts): array
    {
        if ($mounts === []) {
            return [];
        }

        $pageIds = $mounts;
        $seen = array_fill_keys($mounts, true);
        $parents = $mounts;

        for ($depth = 0; $depth < self::WEBMOUNT_DEPTH && $parents !== []; $depth++) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(
                PagePermissionRestriction::class,
                $this->getUserAspect(),
                Permission::PAGE_SHOW,
            ));

            /** @var list<array{uid: int|string}> $rows */
            $rows = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where($queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($parents, ArrayParameterType::INTEGER),
                ))
                ->executeQuery()
                ->fetchAllAssociative();

            $parents = [];
            foreach ($rows as $row) {
                $uid = (int) $row['uid'];
                if (!isset($seen[$uid])) {
                    $seen[$uid] = true;
                    $pageIds[] = $uid;
                    $parents[] = $uid;
                }
            }
        }

        return $pageIds;
    }

    private function getRuntimeCache(): ?FrontendInterface
    {
        try {
            return GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
        } catch (NoSuchCacheException) {
            // No cache registered (e.g. in unit tests) — recompute on every call instead.
            return null;
        }
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1714000010);
        }

        return $backendUser;
    }

    /** @param array<mixed> $groupData */
    private function getGroupDataString(array $groupData, string $key): string
    {
        $value = $groupData[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private function parseCommaSeparatedList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn(string $item): bool => $item !== '',
        ));
    }

    /** @return list<int> */
    private function parseIntList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_map(
            static fn(string $item): int => (int) $item,
            array_filter(
                array_map('trim', explode(',', $value)),
                static fn(string $item): bool => $item !== '',
            ),
        ));
    }
}
