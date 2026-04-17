<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ClientRepository::class)]
final class ClientRepositoryTest extends TestCase
{
    public function testFindByClientIdReturnsClientWhenFound(): void
    {
        $expectedClient = [
            'uid' => 1,
            'client_id' => 'test-client-id',
            'client_name' => 'Test Client',
            'redirect_uris' => '["http://example.com/callback"]',
            'be_user' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($expectedClient);

        $repository = new ClientRepository($connectionPool);
        $client = $repository->findByClientId('test-client-id');

        self::assertSame($expectedClient, $client);
    }

    public function testFindByClientIdReturnsNullWhenNotFound(): void
    {
        $connectionPool = $this->createConnectionPoolWithQueryResult(false);

        $repository = new ClientRepository($connectionPool);
        $client = $repository->findByClientId('nonexistent-client');

        self::assertNull($client);
    }

    public function testValidateRedirectUriReturnsTrueForExactMatch(): void
    {
        $client = [
            'uid' => 1,
            'client_id' => 'test-client-id',
            'client_name' => 'Test Client',
            'redirect_uris' => json_encode(['https://example.com/callback'], JSON_THROW_ON_ERROR),
            'be_user' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($client);

        $repository = new ClientRepository($connectionPool);

        self::assertTrue($repository->validateRedirectUri('test-client-id', 'https://example.com/callback'));
    }

    public function testValidateRedirectUriReturnsTrueForLocalhostDifferentPort(): void
    {
        $client = [
            'uid' => 1,
            'client_id' => 'test-client-id',
            'client_name' => 'Test Client',
            'redirect_uris' => json_encode(['http://localhost:3000/callback'], JSON_THROW_ON_ERROR),
            'be_user' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($client);

        $repository = new ClientRepository($connectionPool);

        self::assertTrue($repository->validateRedirectUri('test-client-id', 'http://localhost:8080/callback'));
    }

    public function testValidateRedirectUriReturnsFalseForNonMatchingUri(): void
    {
        $client = [
            'uid' => 1,
            'client_id' => 'test-client-id',
            'client_name' => 'Test Client',
            'redirect_uris' => json_encode(['https://example.com/callback'], JSON_THROW_ON_ERROR),
            'be_user' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($client);

        $repository = new ClientRepository($connectionPool);

        self::assertFalse($repository->validateRedirectUri('test-client-id', 'https://evil.com/callback'));
    }

    public function testRegisterClientInsertsAndReturnsData(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $repository = new ClientRepository($connectionPool);
        $result = $repository->registerClient('My App', ['https://example.com/callback']);

        self::assertSame('My App', $result['client_name']);
        self::assertSame(['https://example.com/callback'], $result['redirect_uris']);
        self::assertIsString($result['client_id']);
        self::assertNotEmpty($result['client_id']);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function createConnectionPoolWithQueryResult(array|false $row): ConnectionPool
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'dummy'");
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }
}
