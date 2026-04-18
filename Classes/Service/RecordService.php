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

        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
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
     * Find all translations of a record.
     *
     * @return list<array{uid: int, sys_language_uid: int}>
     */
    public function findTranslations(string $table, int $uid, string $languageField, string $transOrigPointerField): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

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
