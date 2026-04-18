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
    public function findByPid(string $table, int $pid, int $limit, int $offset, array $fields): array
    {
        $limit = min(max($limit, 1), 500);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);

        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        /** @var int|string $totalResult */
        $totalResult = $countQueryBuilder
            ->count('uid')
            ->from($table)
            ->where($countQueryBuilder->expr()->eq('pid', $countQueryBuilder->createNamedParameter($pid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();

        $records = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, ParameterType::INTEGER)))
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
}
