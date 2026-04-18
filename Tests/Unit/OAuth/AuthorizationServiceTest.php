<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\OAuth\PkceVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(AuthorizationService::class)]
final class AuthorizationServiceTest extends TestCase
{
    public function testValidateAccessTokenReturnsBeUserUid(): void
    {
        $accessToken = 'valid-access-token';

        $row = [
            'be_user' => 42,
            'access_token_expires' => time() + 3600,
            'revoked' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($row);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        self::assertSame(42, $service->validateAccessToken($accessToken));
    }

    public function testValidateAccessTokenThrowsOnInvalidToken(): void
    {
        $connectionPool = $this->createConnectionPoolWithQueryResult(false);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100030);

        $service->validateAccessToken('invalid-token');
    }

    public function testValidateAccessTokenThrowsOnRevokedToken(): void
    {
        $row = [
            'be_user' => 42,
            'access_token_expires' => time() + 3600,
            'revoked' => 1,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($row);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100031);

        $service->validateAccessToken('some-token');
    }

    public function testValidateAccessTokenThrowsOnExpiredToken(): void
    {
        $row = [
            'be_user' => 42,
            'access_token_expires' => time() - 100,
            'revoked' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($row);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100032);

        $service->validateAccessToken('some-token');
    }

    public function testRefreshTokenThrowsOnInvalidToken(): void
    {
        $connectionPool = $this->createConnectionPoolWithQueryResult(false);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100020);

        $service->refreshToken('invalid-refresh-token', 'client-123');
    }

    public function testRefreshTokenThrowsOnRevokedToken(): void
    {
        $row = [
            'uid' => 1,
            'client_id' => 'client-123',
            'be_user' => 42,
            'refresh_token_expires' => time() + 3600,
            'revoked' => 1,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($row);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100021);

        $service->refreshToken('some-refresh-token', 'client-123');
    }

    public function testRefreshTokenThrowsOnExpiredToken(): void
    {
        $row = [
            'uid' => 1,
            'client_id' => 'client-123',
            'be_user' => 42,
            'refresh_token_expires' => time() - 100,
            'revoked' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($row);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100022);

        $service->refreshToken('some-refresh-token', 'client-123');
    }

    public function testRefreshTokenThrowsOnClientIdMismatch(): void
    {
        $row = [
            'uid' => 1,
            'client_id' => 'client-123',
            'be_user' => 42,
            'refresh_token_expires' => time() + 3600,
            'revoked' => 0,
        ];

        $connectionPool = $this->createConnectionPoolWithQueryResult($row);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100023);

        $service->refreshToken('some-refresh-token', 'wrong-client');
    }

    public function testRevokeTokenRevokesExistingToken(): void
    {
        $row = ['uid' => 5];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('update')->with(
            'tx_msmcpserver_oauth_authorization',
            ['revoked' => 1],
            ['uid' => 5],
        );

        $connectionPool = $this->createConnectionPoolWithQueryAndConnection($row, $connection);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $service->revokeToken('some-token');
    }

    public function testRevokeTokenDoesNothingForUnknownToken(): void
    {
        $connectionPool = $this->createConnectionPoolWithQueryResult(false);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $service->revokeToken('unknown-token');

        // No exception thrown = success (RFC 7009: always return OK for unknown tokens)
        self::assertTrue(true);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function createConnectionPoolWithQueryResult(array|false $row): ConnectionPool
    {
        return $this->createConnectionPoolWithQueryAndConnection($row, $this->createStub(Connection::class));
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function createConnectionPoolWithQueryAndConnection(array|false $row, Connection $connection): ConnectionPool
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'dummy'");
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        return $connectionPool;
    }
}
