<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Authentication;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Authentication\TokenAuthenticator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(TokenAuthenticator::class)]
final class TokenAuthenticatorTest extends TestCase
{
    public function testAuthenticateThrowsOnEmptyToken(): void
    {
        $connectionPool = $this->createStub(ConnectionPool::class);
        $authenticator = new TokenAuthenticator($connectionPool);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000001);

        $authenticator->authenticate('');
    }

    public function testAuthenticateThrowsOnInvalidToken(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

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

        $authenticator = new TokenAuthenticator($connectionPool);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000002);

        $authenticator->authenticate('invalid-token');
    }

    public function testAuthenticateThrowsOnDisabledToken(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'be_user' => 1,
            'expires' => 0,
            'hidden' => 1,
        ]);

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

        $authenticator = new TokenAuthenticator($connectionPool);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000003);

        $authenticator->authenticate('some-token');
    }

    public function testAuthenticateThrowsOnExpiredToken(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'be_user' => 1,
            'expires' => time() - 3600,
            'hidden' => 0,
        ]);

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

        $authenticator = new TokenAuthenticator($connectionPool);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000004);

        $authenticator->authenticate('some-token');
    }

    public function testAuthenticateReturnsBeUserUidOnValidToken(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'be_user' => 42,
            'expires' => 0,
            'hidden' => 0,
        ]);

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

        $authenticator = new TokenAuthenticator($connectionPool);

        self::assertSame(42, $authenticator->authenticate('valid-token'));
    }

    public function testAuthenticateReturnsBeUserUidOnNonExpiredToken(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'be_user' => 7,
            'expires' => time() + 86400,
            'hidden' => 0,
        ]);

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

        $authenticator = new TokenAuthenticator($connectionPool);

        self::assertSame(7, $authenticator->authenticate('valid-token'));
    }
}
