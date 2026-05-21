<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Repository;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Repository\McpSessionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(McpSessionRepository::class)]
final class McpSessionRepositoryTest extends TestCase
{
    public function testFindBySessionIdReturnsRowWhenPresent(): void
    {
        $row = [
            'session_id' => '11111111-1111-1111-1111-111111111111',
            'data' => 'serialized-payload',
            'last_activity' => 1_700_000_000,
        ];

        $connectionPool = $this->createConnectionPoolWithFetchAssociative($row);

        $repository = new McpSessionRepository($connectionPool);
        $result = $repository->findBySessionId('11111111-1111-1111-1111-111111111111');

        self::assertSame($row, $result);
    }

    public function testFindBySessionIdReturnsNullWhenMissing(): void
    {
        $connectionPool = $this->createConnectionPoolWithFetchAssociative(false);

        $repository = new McpSessionRepository($connectionPool);
        $result = $repository->findBySessionId('missing');

        self::assertNull($result);
    }

    public function testFindBySessionIdDecodesResourceData(): void
    {
        $payload = 'binary-blob';
        $stream = fopen('php://memory', 'rb+');
        self::assertNotFalse($stream);
        fwrite($stream, $payload);
        rewind($stream);

        $row = [
            'session_id' => 'abc',
            'data' => $stream,
            'last_activity' => 100,
        ];

        $connectionPool = $this->createConnectionPoolWithFetchAssociative($row);

        $repository = new McpSessionRepository($connectionPool);
        $result = $repository->findBySessionId('abc');

        self::assertNotNull($result);
        self::assertSame($payload, $result['data']);
    }

    public function testTouchUpdatesLastActivity(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_msmcpserver_mcp_session',
                ['last_activity' => 1_700_000_500],
                ['session_id' => 'abc'],
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new McpSessionRepository($connectionPool);
        $repository->touch('abc', 1_700_000_500);
    }

    public function testUpsertUpdatesExistingRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('update');
        $connection->expects(self::never())->method('insert');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        $connectionPool->method('getQueryBuilderForTable')->willReturn(
            $this->createSelectQueryBuilder(['uid' => 1]),
        );

        $repository = new McpSessionRepository($connectionPool);
        $repository->upsert('abc', 'data', 1_700_000_000);
    }

    public function testUpsertInsertsWhenNoExistingRow(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('update');
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_msmcpserver_mcp_session',
                [
                    'session_id' => 'abc',
                    'data' => 'data',
                    'last_activity' => 1_700_000_000,
                    'crdate' => 1_700_000_000,
                ],
                self::anything(),
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);
        $connectionPool->method('getQueryBuilderForTable')->willReturn(
            $this->createSelectQueryBuilder(false),
        );

        $repository = new McpSessionRepository($connectionPool);
        $repository->upsert('abc', 'data', 1_700_000_000);
    }

    public function testDeleteReturnsTrueWhenRowRemoved(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('delete')->willReturn(1);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new McpSessionRepository($connectionPool);

        self::assertTrue($repository->delete('abc'));
    }

    public function testDeleteReturnsFalseWhenNothingMatched(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('delete')->willReturn(0);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new McpSessionRepository($connectionPool);

        self::assertFalse($repository->delete('abc'));
    }

    public function testDeleteExpiredReturnsAffectedRowCount(): void
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeStatement')->willReturn(4);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $repository = new McpSessionRepository($connectionPool);

        self::assertSame(4, $repository->deleteExpired(1_700_000_000));
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function createConnectionPoolWithFetchAssociative(array|false $row): ConnectionPool
    {
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($this->createSelectQueryBuilder($row));

        return $connectionPool;
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function createSelectQueryBuilder(array|false $row): QueryBuilder
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }
}
