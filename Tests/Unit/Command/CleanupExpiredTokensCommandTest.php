<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Command;

use MarekSkopal\MsMcpServer\Command\CleanupExpiredTokensCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(CleanupExpiredTokensCommand::class)]
final class CleanupExpiredTokensCommandTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/tmp/typo3-test',
            '/tmp/typo3-test/public',
            '/tmp/typo3-test/var',
            '/tmp/typo3-test/config',
            '/tmp/typo3-test/index.php',
            'UNIX',
        );
    }

    public function testExecuteDeletesExpiredAuthorizations(): void
    {
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturn($restrictions);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('delete')->willReturn($queryBuilder);
        $queryBuilder->method('where')->willReturn($queryBuilder);
        $queryBuilder->method('executeStatement')->willReturn(5);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $command = new CleanupExpiredTokensCommand($connectionPool);

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('5 expired/revoked OAuth authorizations', $output->fetch());
    }
}
