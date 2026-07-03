<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\PagePermissionRestriction;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class RecordService
{
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

        /** @var list<array{uid: int|string}> $rows */
        $rows = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where($queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
            ))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): int => (int) $row['uid'], $rows);
    }

    /**
     * @param list<string> $fields
     * @return array{records: list<array<string, mixed>>, total: int}
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

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        $records = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $records = $this->workspaceContext->overlayMany($table, $records);

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /**
     * @param list<string> $fields
     * @param array<string, array{operator: string, value: string}> $searchConditions field => {operator, value}
     * @return array{records: list<array<string, mixed>>, total: int}
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

        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder->executeQuery()->fetchOne();

        $records = $queryBuilder
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->orderBy($orderBy ?? 'uid', $orderDirection)
            ->executeQuery()
            ->fetchAllAssociative();

        $records = $this->workspaceContext->overlayMany($table, $records);

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
                $queryBuilder->expr()->in('pid', $pagesQueryBuilder->getSQL()),
            ),
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
            default => throw new \RuntimeException(sprintf('Unsupported search operator "%s".', $operator), 1718100001),
        });
    }

    /**
     * Count records matching optional conditions without fetching them.
     *
     * @param array<string, array{operator: string, value: string}> $searchConditions
     */
    public function count(string $table, ?int $pid = null, array $searchConditions = []): int
    {
        $this->assertReadAccess($table);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $this->workspaceContext->applyRestriction($queryBuilder, $table);

        $queryBuilder->count('uid')->from($table);

        if ($pid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        foreach ($searchConditions as $field => $condition) {
            $this->applyCondition($queryBuilder, $field, $condition);
        }

        /** @var int|string $result */
        $result = $queryBuilder->executeQuery()->fetchOne();

        return (int) $result;
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

        /** @var list<array{uid: int|string, sys_language_uid: int|string}> $rows */
        $rows = $queryBuilder
            ->select('uid', $languageField . ' AS sys_language_uid')
            ->from($table)
            ->where($queryBuilder->expr()->eq($transOrigPointerField, $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->orderBy($languageField, 'ASC')
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
