<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Authentication;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

#[CoversClass(BackendUserBootstrap::class)]
final class BackendUserBootstrapTest extends TestCase
{
    public function testBootstrapThrowsWhenUserNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
        $bootstrap = new BackendUserBootstrap($this->createConnectionPool($result), $languageServiceFactory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000010);

        $bootstrap->bootstrap(999);
    }

    public function testBootstrapThrowsWhenUserDisabled(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'username' => 'admin',
            'disable' => 1,
        ]);

        $languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
        $bootstrap = new BackendUserBootstrap($this->createConnectionPool($result), $languageServiceFactory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000010);

        $bootstrap->bootstrap(1);
    }

    public function testBootstrapThrowsWhenUserDeleted(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'username' => 'admin',
            'deleted' => 1,
        ]);

        $languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
        $bootstrap = new BackendUserBootstrap($this->createConnectionPool($result), $languageServiceFactory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000010);

        $bootstrap->bootstrap(1);
    }

    /**
     * Full bootstrap test requires TYPO3 DI (GeneralUtility::makeInstance for GroupResolver).
     * Error path tests above verify the query and validation logic.
     */

    private function createConnectionPool(Result $result): ConnectionPool
    {
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

        return $connectionPool;
    }
}
