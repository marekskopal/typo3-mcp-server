<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\OAuth\RateLimitService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(RateLimitService::class)]
final class RateLimitServiceTest extends TestCase
{
    public function testCheckReturnsNullWhenDisabled(): void
    {
        $service = $this->createService(['rateLimitEnabled' => '0']);

        self::assertNull($service->check('127.0.0.1', 'authorize_post'));
    }

    public function testCheckReturnsNullWhenIpIsEmpty(): void
    {
        $service = $this->createService();

        self::assertNull($service->check('', 'authorize_post'));
    }

    public function testCheckReturnsNullForUnknownEndpoint(): void
    {
        $service = $this->createService();

        self::assertNull($service->check('127.0.0.1', 'unknown_endpoint'));
    }

    public function testCheckReturnsNullWhenUnderLimit(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with('tx_msmcpserver_rate_limit', self::callback(function (array $data): bool {
                self::assertSame('127.0.0.1', $data['ip_address']);
                self::assertSame('authorize_post', $data['endpoint']);
                self::assertSame(1, $data['hit_count']);

                return true;
            }));

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $service = $this->createService(connectionPool: $connectionPool);

        self::assertNull($service->check('127.0.0.1', 'authorize_post'));
    }

    public function testCheckReturnsRetryAfterWhenOverLimit(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(6);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('executeStatement')->willReturn(1);

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException(
            $this->createStub(UniqueConstraintViolationException::class),
        );
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $service = $this->createService(['rateLimitAuthorize' => '5'], $connectionPool);
        $retryAfter = $service->check('127.0.0.1', 'authorize_post');

        self::assertNotNull($retryAfter);
        self::assertGreaterThanOrEqual(1, $retryAfter);
    }

    public function testCheckIncrementsOnExistingRow(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(2);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn("'dummy'");
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::once())->method('executeStatement')->willReturn(1);

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException(
            $this->createStub(UniqueConstraintViolationException::class),
        );
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $service = $this->createService(connectionPool: $connectionPool);

        self::assertNull($service->check('127.0.0.1', 'authorize_post'));
    }

    public function testDeleteExpiredEntriesDeletesOldRows(): void
    {
        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(5);

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $service = $this->createService(connectionPool: $connectionPool);

        self::assertSame(5, $service->deleteExpiredEntries());
    }

    public function testCheckUsesCorrectLimitsPerEndpoint(): void
    {
        // Token endpoint has limit 20 by default, so 1 hit should be allowed
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $service = $this->createService(connectionPool: $connectionPool);

        self::assertNull($service->check('127.0.0.1', 'token_post'));
    }

    /** @param array<string, string> $config */
    private function createService(array $config = [], ?ConnectionPool $connectionPool = null): RateLimitService
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($config);

        return new RateLimitService(
            $connectionPool ?? $this->createStub(ConnectionPool::class),
            $extensionConfiguration,
        );
    }

    /** @return QueryBuilder&\PHPUnit\Framework\MockObject\Stub */
    private function createQueryBuilderStub(): QueryBuilder
    {
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn("'dummy'");
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();

        return $queryBuilder;
    }
}
