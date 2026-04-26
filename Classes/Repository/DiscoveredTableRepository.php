<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class DiscoveredTableRepository
{
    private const string TABLE = 'tx_msmcpserver_discovered_table';

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /** @return list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> */
    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'table_name', 'label', 'prefix', 'enabled')
            ->from(self::TABLE)
            ->orderBy('table_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /** @return list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> */
    public function findEnabled(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var list<array{uid: int, table_name: string, label: string, prefix: string, enabled: int}> $rows */
        $rows = $queryBuilder
            ->select('uid', 'table_name', 'label', 'prefix', 'enabled')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)))
            ->orderBy('table_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /** @return array{uid: int, table_name: string, label: string, prefix: string, enabled: int}|null */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var array{uid: int, table_name: string, label: string, prefix: string, enabled: int}|false $row */
        $row = $queryBuilder
            ->select('uid', 'table_name', 'label', 'prefix', 'enabled')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /** Inserts a new row only if the table_name does not already exist. Returns true if inserted. */
    public function insertIfNew(string $tableName, string $label, string $prefix): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var array{uid: int}|false $existing */
        $existing = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('table_name', $queryBuilder->createNamedParameter($tableName)))
            ->executeQuery()
            ->fetchAssociative();

        if ($existing !== false) {
            return false;
        }

        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'table_name' => $tableName,
            'label' => $label,
            'prefix' => $prefix,
            'enabled' => 0,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return true;
    }

    public function update(int $uid, string $label, string $prefix): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'label' => $label,
            'prefix' => $prefix,
            'tstamp' => time(),
        ], ['uid' => $uid]);
    }

    public function setEnabled(int $uid, bool $enabled): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'enabled' => $enabled ? 1 : 0,
            'tstamp' => time(),
        ], ['uid' => $uid]);
    }
}
