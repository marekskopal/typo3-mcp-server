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
            'token_family' => 'fam-1',
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

    public function testRefreshTokenReuseRevokesEntireFamily(): void
    {
        $row = [
            'uid' => 1,
            'client_id' => 'client-123',
            'be_user' => 42,
            'token_family' => 'fam-1',
            'refresh_token_expires' => time() + 3600,
            'revoked' => 1,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with('tx_msmcpserver_oauth_authorization', ['revoked' => 1], ['token_family' => 'fam-1']);

        $connectionPool = $this->createConnectionPoolWithQueryAndConnection($row, $connection);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100021);

        $service->refreshToken('stolen-refresh-token', 'client-123');
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

    public function testRefreshTokenRotatesWhenConditionalUpdateSucceeds(): void
    {
        $row = [
            'uid' => 1,
            'client_id' => 'client-123',
            'be_user' => 42,
            'token_family' => 'fam-1',
            'refresh_token_expires' => time() + 3600,
            'revoked' => 0,
        ];

        // The atomic rotation UPDATE affects exactly one row (we won the race).
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with('tx_msmcpserver_oauth_authorization', ['revoked' => 1], ['uid' => 1, 'revoked' => 0])
            ->willReturn(1);

        $connectionPool = $this->createConnectionPoolWithQueryAndConnection($row, $connection);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            $this->clientRepositoryFindingClient(),
            $this->createStub(ExtensionConfiguration::class),
        );

        $pair = $service->refreshToken('some-refresh-token', 'client-123');

        self::assertNotSame('', $pair->accessToken);
        self::assertNotSame('', $pair->refreshToken);
    }

    public function testRefreshTokenConcurrentRotationRevokesFamily(): void
    {
        // Token passes every validation (still active), yet the conditional UPDATE affects zero
        // rows because a concurrent request already flipped `revoked` — the TOCTOU race. That must
        // be treated as reuse: revoke the family and reject, rather than mint a second token pair.
        $row = [
            'uid' => 1,
            'client_id' => 'client-123',
            'be_user' => 42,
            'token_family' => 'fam-1',
            'refresh_token_expires' => time() + 3600,
            'revoked' => 0,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $criteria): int {
                if (array_key_exists('token_family', $criteria)) {
                    self::assertSame(['token_family' => 'fam-1'], $criteria);

                    return 1;
                }

                self::assertSame(['uid' => 1, 'revoked' => 0], $criteria);

                // Lost the race.
                return 0;
            });

        $connectionPool = $this->createConnectionPoolWithQueryAndConnection($row, $connection);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            $this->clientRepositoryFindingClient(),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100021);

        $service->refreshToken('some-refresh-token', 'client-123');
    }

    public function testRefreshTokenRevokesFamilyWhenClientNoLongerExists(): void
    {
        // The token itself is still valid, but its client has been deleted/disabled since the
        // grant was issued. The refresh must fail and the whole family must be revoked.
        $row = [
            'uid' => 1,
            'client_id' => 'client-123',
            'be_user' => 42,
            'token_family' => 'fam-1',
            'refresh_token_expires' => time() + 3600,
            'revoked' => 0,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with('tx_msmcpserver_oauth_authorization', ['revoked' => 1], ['token_family' => 'fam-1']);

        $connectionPool = $this->createConnectionPoolWithQueryAndConnection($row, $connection);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn(null);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            $clientRepository,
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100024);

        $service->refreshToken('some-refresh-token', 'client-123');
    }

    public function testExchangeCodeIssuesTokenPairWhenConditionalUpdateSucceeds(): void
    {
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $row = [
            'uid' => 7,
            'client_id' => 'client-123',
            'be_user' => 42,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'redirect_uri' => 'https://app/cb',
            'code_expires' => time() + 60,
            'revoked' => 0,
        ];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_msmcpserver_oauth_authorization',
                ['authorization_code_hash' => '', 'revoked' => 1],
                ['uid' => 7, 'revoked' => 0],
            )
            ->willReturn(1);

        $connectionPool = $this->createConnectionPoolWithQueryAndConnection($row, $connection);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $pair = $service->exchangeCode('some-code', $verifier, 'client-123', 'https://app/cb');

        self::assertNotSame('', $pair->accessToken);
        self::assertNotSame('', $pair->refreshToken);
    }

    public function testExchangeCodeThrowsWhenAlreadyConsumed(): void
    {
        // Valid code that passes PKCE, but the atomic consume UPDATE affects zero rows because a
        // concurrent exchange (or replay) already consumed it. No token pair may be issued.
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $row = [
            'uid' => 7,
            'client_id' => 'client-123',
            'be_user' => 42,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'redirect_uri' => 'https://app/cb',
            'code_expires' => time() + 60,
            'revoked' => 0,
        ];

        $connection = $this->createMock(Connection::class);
        // Lost the race / replay: the atomic consume affects zero rows.
        $connection->expects(self::once())
            ->method('update')
            ->willReturn(0);

        $connectionPool = $this->createConnectionPoolWithQueryAndConnection($row, $connection);

        $service = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($this->createStub(ConnectionPool::class)),
            $this->createStub(ExtensionConfiguration::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712100016);

        $service->exchangeCode('some-code', $verifier, 'client-123', 'https://app/cb');
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

    private function clientRepositoryFindingClient(): ClientRepository
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-123',
            'client_name' => 'Test Client',
            'redirect_uris' => '["https://app/cb"]',
            'be_user' => 42,
        ]);

        return $clientRepository;
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

        $queryBuilder = $this->createStub(QueryBuilder::class);
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
