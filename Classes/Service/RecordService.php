<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Mcp\Exception\ToolCallException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\PagePermissionRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class RecordService
{
    /** Operators accepted in search conditions; anything else is rejected with a client-visible error. */
    public const array SUPPORTED_OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'null', 'notNull', 'like'];

    /**
     * Upper bound on rows read while paginating or counting over a workspace overlay. The overlay
     * runs in PHP, so the only way to know how many rows survive it is to look at them; this keeps
     * that bounded. Results that hit the cap are reported as inexact rather than silently truncated.
     */
    private const int OVERLAY_SCAN_LIMIT = 10000;

    public function __construct(
        private ConnectionPool $connectionPool,
        private WorkspaceContextService $workspaceContext,
        private PermissionService $permissionService,
    ) {
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function findByUid(string $table, int $uid, array $fields): ?array
    {
        $this->assertReadAccess($table);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)));
        $this->applyPageReadConstraint($queryBuilder, $table);

        $row = $queryBuilder
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->workspaceContext->overlay($table, $row);
    }

    /**
     * Return the subset of UIDs that actually exist in the given table.
     *
     * @param list<int> $uids
     * @return list<int>
     */
    public function findExistingUids(string $table, array $uids): array
    {
        $this->assertReadAccess($table);

        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $queryBuilder
            ->select('uid')
            ->from($table)
            ->where($queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
            ));
        // Records on pages the user may not show must not be probeable here either, otherwise
        // batch-tool "skipped" responses become a UID existence oracle for restricted pages.
        $this->applyPageReadConstraint($queryBuilder, $table);

        /** @var list<array{uid: int|string}> $rows */
        $rows = $queryBuilder
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int) $row['uid'], $rows);
    }

    /**
     * In a non-live workspace on a workspace-aware table the result carries `hasMore` instead of
     * `total`; see paginateOverlaid() for why an exact total is not available there.
     *
     * @param list<string> $fields
     * @return array{records: list<array<string, mixed>>, total?: int, hasMore?: bool, workspaceOverlay?: string}
     */
    public function findByPid(
        string $table,
        int $pid,
        int $limit,
        int $offset,
        array $fields,
        ?int $sysLanguageUid = null,
        ?string $languageField = null,
    ): array {
        $this->assertReadAccess($table);

        $limit = min(max($limit, 1), 500);
        $offset = max($offset, 0);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($countQueryBuilder, $table);
        $countQueryBuilder
            ->count('uid')
            ->from($table)
            ->where($countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)));

        $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)));

        $this->applyPageReadConstraint($queryBuilder, $table);
        $this->applyPageReadConstraint($countQueryBuilder, $table);

        if ($sysLanguageUid !== null && $languageField !== null) {
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq(
                    $languageField,
                    $countQueryBuilder->createNamedParameter($sysLanguageUid, ParameterType::INTEGER),
                ),
            );
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($sysLanguageUid, ParameterType::INTEGER)),
            );
        }

        $queryBuilder->orderBy('uid', 'ASC');

        if ($this->overlayApplies($table)) {
            return $this->paginateOverlaid($queryBuilder, $table, $limit, $offset);
        }

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        $records = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /**
     * In a non-live workspace on a workspace-aware table the result carries `hasMore` instead of
     * `total`; see paginateOverlaid() for why an exact total is not available there.
     *
     * @param list<string> $fields
     * @param array<string, array{operator: string, value: string}> $searchConditions field => {operator, value}
     * @return array{records: list<array<string, mixed>>, total?: int, hasMore?: bool, workspaceOverlay?: string}
     */
    public function search(
        string $table,
        array $searchConditions,
        int $limit,
        int $offset,
        array $fields,
        ?int $pid = null,
        ?string $orderBy = null,
        string $orderDirection = 'ASC',
    ): array
    {
        $this->assertReadAccess($table);

        $limit = min(max($limit, 1), 500);
        $offset = max($offset, 0);

        if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
            $orderDirection = 'ASC';
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($countQueryBuilder, $table);

        $queryBuilder->select(...$fields)->from($table);
        $countQueryBuilder->count('uid')->from($table);

        $this->applyPageReadConstraint($queryBuilder, $table);
        $this->applyPageReadConstraint($countQueryBuilder, $table);

        if ($pid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        foreach ($searchConditions as $field => $condition) {
            $this->applyCondition($queryBuilder, $field, $condition);
            $this->applyCondition($countQueryBuilder, $field, $condition);
        }

        $queryBuilder->orderBy($orderBy ?? 'uid', $orderDirection);

        if ($this->overlayApplies($table)) {
            return $this->paginateOverlaid($queryBuilder, $table, $limit, $offset);
        }

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        $records = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /**
     * Enforce the backend user's `tables_select` grant before reading a table.
     * Without this, the QueryBuilder (which performs no permission checks of its own)
     * would let any authenticated user read every TCA table, e.g. be_users or fe_users.
     */
    private function assertReadAccess(string $table): void
    {
        if (!$this->permissionService->canSelectTable($table)) {
            throw new \RuntimeException(
                sprintf('Access denied: you do not have read permission for table "%s".', $table),
                1718100000,
            );
        }
    }

    /**
     * Restrict a read query to records the user is allowed to see by page permission.
     *
     * `tables_select` (checked in assertReadAccess) is table-wide and does not honour the per-page
     * perms_* ACLs the TYPO3 backend enforces, so without this an editor could read pages and
     * content across the whole installation. Admins keep unrestricted access (matching core).
     * For `pages` the perms clause is applied directly; other tables are constrained to rows whose
     * page (pid) the user may show.
     */
    private function applyPageReadConstraint(QueryBuilder $queryBuilder, string $table): void
    {
        if ($this->permissionService->isAdmin()) {
            return;
        }

        $restriction = GeneralUtility::makeInstance(
            PagePermissionRestriction::class,
            $this->permissionService->getUserAspect(),
            Permission::PAGE_SHOW,
        );

        if ($table === 'pages') {
            // PagePermissionRestriction only constrains the `pages` table, so it applies directly.
            $queryBuilder->getRestrictions()->add($restriction);
            $queryBuilder->andWhere($this->buildWebmountCondition($queryBuilder, 'uid'));

            return;
        }

        // Other tables are bound to a page via pid: restrict to rows whose page the user may show.
        $pagesQueryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $pagesQueryBuilder->getRestrictions()->removeAll();
        $pagesQueryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $pagesQueryBuilder->getRestrictions()->add($restriction);
        $pagesQueryBuilder
            ->select('uid')
            ->from('pages');

        // Root-level records (pid 0, e.g. rootLevel tables like sys_redirect) sit outside the page
        // tree, so no perms_* ACL applies to them; they remain readable under the table-level grant
        // checked in assertReadAccess. Without this, the pid IN (pages…) subquery would hide them.
        $queryBuilder->andWhere(
            $queryBuilder->expr()->or(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->in('pid', $pagesQueryBuilder->getSQL()),
                    $this->buildWebmountCondition($queryBuilder, 'pid'),
                ),
            ),
        );
    }

    /**
     * Condition confining a page reference to the user's webmounts. The perms_* ACL alone is not
     * enough: the backend additionally restricts every non-admin to the page trees mounted for
     * them, so a page whose ACL would grant SHOW is still invisible outside those mounts.
     * Only called for non-admins (admins skip the read constraint entirely).
     */
    private function buildWebmountCondition(QueryBuilder $queryBuilder, string $field): string
    {
        $webmountPageIds = $this->permissionService->getWebmountPageIds() ?? [];

        if ($webmountPageIds === []) {
            // A non-admin without webmounts can reach no page at all.
            return '1 = 0';
        }

        return $queryBuilder->expr()->in(
            $field,
            $queryBuilder->createNamedParameter($webmountPageIds, ArrayParameterType::INTEGER),
        );
    }

    /** @param array{operator: string, value: string} $condition */
    private function applyCondition(QueryBuilder $queryBuilder, string $field, array $condition): void
    {
        $operator = $condition['operator'];
        $value = $condition['value'];
        $expr = $queryBuilder->expr();

        $queryBuilder->andWhere(match ($operator) {
            'eq' => $expr->eq($field, $queryBuilder->createNamedParameter($value)),
            'neq' => $expr->neq($field, $queryBuilder->createNamedParameter($value)),
            'gt' => $expr->gt($field, $queryBuilder->createNamedParameter($value)),
            'gte' => $expr->gte($field, $queryBuilder->createNamedParameter($value)),
            'lt' => $expr->lt($field, $queryBuilder->createNamedParameter($value)),
            'lte' => $expr->lte($field, $queryBuilder->createNamedParameter($value)),
            'in' => $expr->in(
                $field,
                $queryBuilder->createNamedParameter(
                    array_map('trim', explode(',', $value)),
                    ArrayParameterType::STRING,
                ),
            ),
            'null' => $expr->isNull($field),
            'notNull' => $expr->isNotNull($field),
            'like' => $expr->like(
                $field,
                $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($value) . '%'),
            ),
            // Reject unknown operators rather than silently falling back to a broad LIKE, which
            // could return far more rows than intended (and feed downstream batch operations).
            // ToolCallException is relayed verbatim to the MCP client (ErrorHandlingProxy passes it
            // through), so the caller learns which operator was wrong instead of "internal error".
            default => throw new ToolCallException(
                sprintf(
                    'Unsupported search operator "%s". Supported operators: %s.',
                    $operator,
                    implode(', ', self::SUPPORTED_OPERATORS),
                ),
                1718100001,
            ),
        });
    }

    /**
     * Count records matching optional conditions without fetching them.
     *
     * `exact` is false only in a non-live workspace on a very large result set, where the overlay
     * walk hits OVERLAY_SCAN_LIMIT and the returned count is a floor.
     *
     * @param array<string, array{operator: string, value: string}> $searchConditions
     * @return array{count: int, exact: bool}
     */
    public function count(string $table, ?int $pid = null, array $searchConditions = []): array
    {
        $this->assertReadAccess($table);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        // A SQL COUNT cannot be workspace-overlaid, so in a non-live workspace it disagrees with
        // the row count search() returns for the same query. Count what survives the overlay.
        if ($this->overlayApplies($table)) {
            $queryBuilder->select('*')->from($table);
        } else {
            $queryBuilder->count('uid')->from($table);
        }

        $this->applyPageReadConstraint($queryBuilder, $table);

        if ($pid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        foreach ($searchConditions as $field => $condition) {
            $this->applyCondition($queryBuilder, $field, $condition);
        }

        if ($this->overlayApplies($table)) {
            $scan = $this->scanOverlaid($queryBuilder->orderBy('uid', 'ASC'), $table, self::OVERLAY_SCAN_LIMIT);

            return ['count' => count($scan['records']), 'exact' => !$scan['capped']];
        }

        /** @var int|string $result */
        $result = $queryBuilder->executeQuery()->fetchOne();

        return ['count' => (int) $result, 'exact' => true];
    }

    /**
     * True when reads on $table go through a workspace overlay, i.e. rows can be dropped in PHP
     * after the query has already applied LIMIT/OFFSET.
     */
    private function overlayApplies(string $table): bool
    {
        return !$this->workspaceContext->isLive() && $this->workspaceContext->isTableWorkspaceAware($table);
    }

    /**
     * Paginate over the workspace-overlaid result set.
     *
     * The overlay drops rows hidden in the current workspace (a DELETE_PLACEHOLDER, for one), so
     * applying setMaxResults() *before* it returned short pages while later records still existed,
     * and a client walking `offset += limit` silently skipped records. The separate COUNT was never
     * overlaid either, so `total` over-reported and appeared to confirm nothing was missing.
     *
     * Fetch, overlay, then slice — and report `hasMore` rather than a total, because knowing the
     * exact total would mean overlaying every matching row.
     *
     * @return array{records: list<array<string, mixed>>, hasMore: bool, workspaceOverlay: string}
     */
    private function paginateOverlaid(QueryBuilder $queryBuilder, string $table, int $limit, int $offset): array
    {
        // One past the page, so a full page can be told apart from the last one.
        $scan = $this->scanOverlaid($queryBuilder, $table, $offset + $limit + 1);

        return [
            'records' => array_slice($scan['records'], $offset, $limit),
            'hasMore' => count($scan['records']) > $offset + $limit,
            'workspaceOverlay' => sprintf(
                'Records are overlaid for workspace %d. An exact total is not available here'
                    . ' — page with offset and hasMore.',
                $this->workspaceContext->getCurrentWorkspaceId(),
            ),
        ];
    }

    /**
     * Reads windows of rows and overlays them until $needed overlaid rows are available or the
     * source is exhausted, capped at OVERLAY_SCAN_LIMIT rows read.
     *
     * @return array{records: list<array<string, mixed>>, capped: bool}
     */
    private function scanOverlaid(QueryBuilder $queryBuilder, string $table, int $needed): array
    {
        $overlaid = [];
        $read = 0;
        $window = min(max($needed, 100), self::OVERLAY_SCAN_LIMIT);

        while (count($overlaid) < $needed && $read < self::OVERLAY_SCAN_LIMIT) {
            $rows = (clone $queryBuilder)
                ->setMaxResults(min($window, self::OVERLAY_SCAN_LIMIT - $read))
                ->setFirstResult($read)
                ->executeQuery()
                ->fetchAllAssociative();

            if ($rows === []) {
                return ['records' => $overlaid, 'capped' => false];
            }

            $read += count($rows);
            foreach ($this->workspaceContext->overlayMany($table, $rows) as $row) {
                $overlaid[] = $row;
            }

            // A short read means the source is exhausted, so what we have is everything.
            if (count($rows) < $window) {
                return ['records' => $overlaid, 'capped' => false];
            }
        }

        return ['records' => $overlaid, 'capped' => count($overlaid) < $needed];
    }

    /**
     * Find all file references for a record field.
     *
     * @return list<array<string, mixed>>
     */
    public function findFileReferences(string $table, int $uid, string $fieldName): array
    {
        // Gate on read access to the parent table, otherwise reference metadata (title, link, …)
        // of records the user may not read would leak through this path.
        $this->assertReadAccess($table);

        // The reference rows carry no page context of their own; gate on the visibility of the
        // parent record instead, which applies the page-permission read constraint via findByUid.
        if ($this->findByUid($table, $uid, ['uid']) === null) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, 'sys_file_reference');

        $rows = $queryBuilder
            ->select('uid', 'uid_local', 'title', 'description', 'alternative', 'link', 'crop', 'autoplay', 'sorting_foreign')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->andWhere($queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)))
            ->andWhere($queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldName)))
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->workspaceContext->overlayMany('sys_file_reference', $rows);
    }

    /**
     * Find all translations of a record.
     *
     * @return list<array{uid: int, sys_language_uid: int}>
     */
    public function findTranslations(string $table, int $uid, string $languageField, string $transOrigPointerField): array
    {
        $this->assertReadAccess($table);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $queryBuilder
            ->select('uid', $languageField . ' AS sys_language_uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq($transOrigPointerField, $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->orderBy($languageField, 'ASC');
        $this->applyPageReadConstraint($queryBuilder, $table);

        /** @var list<array{uid: int|string, sys_language_uid: int|string}> $rows */
        $rows = $queryBuilder
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'uid' => (int) $row['uid'],
                'sys_language_uid' => (int) $row['sys_language_uid'],
            ],
            $rows,
        );
    }
}
