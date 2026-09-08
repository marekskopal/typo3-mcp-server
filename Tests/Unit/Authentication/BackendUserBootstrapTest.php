<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Authentication;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Session\Backend\DatabaseSessionBackend;
use TYPO3\CMS\Core\Session\UserSession;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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

    public function testBootstrapThrowsWhenStarttimeInFuture(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'username' => 'admin',
            'starttime' => time() + 3600,
        ]);

        $languageServiceFactory = $this->createStub(LanguageServiceFactory::class);
        $bootstrap = new BackendUserBootstrap($this->createConnectionPool($result), $languageServiceFactory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1712000010);

        $bootstrap->bootstrap(1);
    }

    public function testBootstrapThrowsWhenEndtimeInPast(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn([
            'uid' => 1,
            'username' => 'admin',
            'endtime' => time() - 3600,
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

    /**
     * TYPO3's redirects extension reacts to a slug change from a DataHandler hook that ends in
     * BackendUtility::setUpdateSignal() → BackendUserAuthentication::getModuleData(…, 'ses'),
     * which hashes the current session id. Outside CLI, a user without a session made that
     * throw *after* the row was written (asl-brno, pages_update with title+slug+hidden).
     */
    public function testInitializeSessionGivesTheUserASessionSoSessionScopedModuleDataWorks(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['session']['BE'] = [
            'backend' => DatabaseSessionBackend::class,
            'options' => ['table' => 'be_sessions'],
        ];
        $GLOBALS['TYPO3_CONF_VARS']['BE']['sessionTimeout'] = 28800;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lockIP'] = 0;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lockIPv6'] = 0;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['lifetime'] = 0;
        // TYPO3 v13 stamps an anonymous session with EXEC_TIME (v14 asks a clock); bootstrap sets it.
        $GLOBALS['EXEC_TIME'] = time();

        try {
            $backendUser = new BackendUserAuthentication();
            $bootstrap = new BackendUserBootstrap(
                $this->createStub(ConnectionPool::class),
                $this->createStub(LanguageServiceFactory::class),
            );

            $bootstrap->initializeSession($backendUser);

            self::assertInstanceOf(UserSession::class, $backendUser->getSession());
            self::assertNotSame('', $backendUser->getSession()->getIdentifier());
            // What setUpdateSignal() does: read session-scoped module data, then push it back.
            self::assertNull($backendUser->getModuleData('BackendUtility::getUpdateSignal', 'ses'));
            $backendUser->pushModuleData('BackendUtility::getUpdateSignal', ['x' => 1], true);
            self::assertSame(['x' => 1], $backendUser->getModuleData('BackendUtility::getUpdateSignal', 'ses'));
        } finally {
            GeneralUtility::purgeInstances();
            unset($GLOBALS['TYPO3_CONF_VARS'], $GLOBALS['EXEC_TIME']);
        }
    }
}
