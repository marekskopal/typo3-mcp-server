<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class McpSessionRepository
{
    private const string TABLE = 'tx_msmcpserver_mcp_session';

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /** @return array{session_id: string, data: string, last_activity: int}|null */
    public function findBySessionId(string $sessionId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        /** @var array{session_id: string, data: string|resource|null, last_activity: int}|false $row */
        $row = $queryBuilder
            ->select('session_id', 'data', 'last_activity')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('session_id', $queryBuilder->createNamedParameter($sessionId)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $rawData = $row['data'];
        $data = is_resource($rawData) ? (string) stream_get_contents($rawData) : (is_string($rawData) ? $rawData : '');

        return [
            'session_id' => $row['session_id'],
            'data' => $data,
            'last_activity' => $row['last_activity'],
        ];
    }

    public function touch(string $sessionId, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            ['last_activity' => $now],
            ['session_id' => $sessionId],
        );
    }

    public function upsert(string $sessionId, string $data, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $affected = $connection->update(
            self::TABLE,
            ['data' => $data, 'last_activity' => $now],
            ['session_id' => $sessionId],
            ['data' => ParameterType::LARGE_OBJECT],
        );

        if ($affected !== 0) {
            return;
        }

        $connection->insert(
            self::TABLE,
            [
                'session_id' => $sessionId,
                'data' => $data,
                'last_activity' => $now,
                'crdate' => $now,
            ],
            ['data' => ParameterType::LARGE_OBJECT],
        );
    }

    public function delete(string $sessionId): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affected = $connection->delete(self::TABLE, ['session_id' => $sessionId]);

        return $affected > 0;
    }

    public function deleteExpired(int $cutoff): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int) $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'last_activity',
                    $queryBuilder->createNamedParameter($cutoff, ParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }
}
