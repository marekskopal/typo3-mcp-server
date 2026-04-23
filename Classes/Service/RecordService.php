<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class RecordService
{
    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function findByUid(string $table, int $uid, array $fields): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
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
        $limit = min(max($limit, 1), 500);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()->removeAll();
        $countQueryBuilder
            ->count('uid')
            ->from($table)
            ->where($countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)));

        $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)));

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

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /**
     * @param list<string> $fields
     * @param array<string, string> $searchConditions field => search value (LIKE %value%)
     * @return array{records: list<array<string, mixed>>, total: int}
     */
    public function search(string $table, array $searchConditions, int $limit, int $offset, array $fields, ?int $pid = null,): array
    {
        $limit = min(max($limit, 1), 500);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $countQueryBuilder->getRestrictions()->removeAll();

        $queryBuilder->select(...$fields)->from($table);
        $countQueryBuilder->count('uid')->from($table);

        if ($pid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)),
            );
        }

        foreach ($searchConditions as $field => $value) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like($field, $queryBuilder->createNamedParameter('%' . $value . '%')),
            );
            $countQueryBuilder->andWhere(
                $countQueryBuilder->expr()->like($field, $countQueryBuilder->createNamedParameter('%' . $value . '%')),
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

        return [
            'records' => $records,
            'total' => (int) $totalResult,
        ];
    }

    /**
     * Find all file references for a record field.
     *
     * @return list<array<string, mixed>>
     */
    public function findFileReferences(string $table, int $uid, string $fieldName): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'uid_local', 'title', 'description', 'alternative', 'link', 'crop', 'autoplay', 'sorting_foreign')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->andWhere($queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)))
            ->andWhere($queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldName)))
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Find all translations of a record.
     *
     * @return list<array{uid: int, sys_language_uid: int}>
     */
    public function findTranslations(string $table, int $uid, string $languageField, string $transOrigPointerField): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

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
