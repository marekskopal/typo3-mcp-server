<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Repository;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Repository\DiscoveredTableRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(DiscoveredTableRepository::class)]
final class DiscoveredTableRepositoryTest extends TestCase
{
    public function testFindAllReturnsAllRows(): void
    {
        $rows = [
            ['uid' => 1, 'table_name' => 'tx_news_domain_model_news', 'label' => 'News', 'prefix' => 'news', 'enabled' => 1],
            ['uid' => 2, 'table_name' => 'tx_blog_domain_model_post', 'label' => 'Blog Post', 'prefix' => 'blog_post', 'enabled' => 0],
        ];

        $connectionPool = $this->createConnectionPoolWithFetchAll($rows);

        $repository = new DiscoveredTableRepository($connectionPool);
        $result = $repository->findAll();

        self::assertSame($rows, $result);
    }

    public function testFindEnabledReturnsOnlyEnabledRows(): void
    {
        $rows = [
            ['uid' => 1, 'table_name' => 'tx_news_domain_model_news', 'label' => 'News', 'prefix' => 'news', 'enabled' => 1],
        ];

        $connectionPool = $this->createConnectionPoolWithFetchAll($rows);

        $repository = new DiscoveredTableRepository($connectionPool);
        $result = $repository->findEnabled();

        self::assertSame($rows, $result);
    }

    public function testFindByUidReturnsRowWhenFound(): void
    {
        $row = ['uid' => 1, 'table_name' => 'tx_news_domain_model_news', 'label' => 'News', 'prefix' => 'news', 'enabled' => 1];

        $connectionPool = $this->createConnectionPoolWithFetchAssociative($row);

        $repository = new DiscoveredTableRepository($connectionPool);
        $result = $repository->findByUid(1);

        self::assertSame($row, $result);
    }

    public function testFindByUidReturnsNullWhenNotFound(): void
    {
        $connectionPool = $this->createConnectionPoolWithFetchAssociative(false);

        $repository = new DiscoveredTableRepository($connectionPool);
        $result = $repository->findByUid(999);

        self::assertNull($result);
    }

    public function testInsertIfNewInsertsWhenTableDoesNotExist(): void
    {
        $queryResult = $this->createStub(Result::class);
        $queryResult->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('executeQuery')->willReturn($queryResult);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_msmcpserver_discovered_table',
                self::callback(function (array $data): bool {
                    self::assertSame('tx_news_domain_model_news', $data['table_name']);
                    self::assertSame('News', $data['label']);
                    self::assertSame('news', $data['prefix']);
                    self::assertSame(0, $data['enabled']);

                    return true;
                }),
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new DiscoveredTableRepository($connectionPool);
        $result = $repository->insertIfNew('tx_news_domain_model_news', 'News', 'news');

        self::assertTrue($result);
    }

    public function testInsertIfNewReturnsFalseWhenTableAlreadyExists(): void
    {
        $queryResult = $this->createStub(Result::class);
        $queryResult->method('fetchAssociative')->willReturn(['uid' => 1]);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('executeQuery')->willReturn($queryResult);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new DiscoveredTableRepository($connectionPool);
        $result = $repository->insertIfNew('tx_news_domain_model_news', 'News', 'news');

        self::assertFalse($result);
    }

    public function testUpdateSetsLabelAndPrefix(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_msmcpserver_discovered_table',
                self::callback(function (array $data): bool {
                    self::assertSame('Updated Label', $data['label']);
                    self::assertSame('updated_prefix', $data['prefix']);
                    self::assertArrayHasKey('tstamp', $data);

                    return true;
                }),
                ['uid' => 5],
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new DiscoveredTableRepository($connectionPool);
        $repository->update(5, 'Updated Label', 'updated_prefix');
    }

    public function testSetEnabledTogglesFlag(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_msmcpserver_discovered_table',
                self::callback(function (array $data): bool {
                    self::assertSame(1, $data['enabled']);
                    self::assertArrayHasKey('tstamp', $data);

                    return true;
                }),
                ['uid' => 3],
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new DiscoveredTableRepository($connectionPool);
        $repository->setEnabled(3, true);
    }

    public function testSetEnabledDisablesFlag(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_msmcpserver_discovered_table',
                self::callback(function (array $data): bool {
                    self::assertSame(0, $data['enabled']);

                    return true;
                }),
                ['uid' => 3],
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new DiscoveredTableRepository($connectionPool);
        $repository->setEnabled(3, false);
    }

    /** @return QueryBuilder&\PHPUnit\Framework\MockObject\Stub */
    private function createQueryBuilderStub(): QueryBuilder
    {
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'dummy'");

        return $queryBuilder;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createConnectionPoolWithFetchAll(array $rows): ConnectionPool
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function createConnectionPoolWithFetchAssociative(array|false $row): ConnectionPool
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }
}
