<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Command;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use MarekSkopal\MsMcpServer\Command\McpServerCommand;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(McpServerCommand::class)]
final class McpServerCommandTest extends TestCase
{
    public function testExecuteFailsWhenUserNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturn($restrictions);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturn($queryBuilder);
        $queryBuilder->method('from')->willReturn($queryBuilder);
        $queryBuilder->method('where')->willReturn($queryBuilder);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $backendUserBootstrap = $this->createStub(BackendUserBootstrap::class);
        $mcpServerFactory = $this->createStub(McpServerFactory::class);

        $command = new McpServerCommand($connectionPool, $backendUserBootstrap, $mcpServerFactory, new NullLogger());

        $input = new ArrayInput(['--user' => 'nonexistent']);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('nonexistent', $output->fetch());
    }

    public function testExecuteBootstrapsUserBeforeCreatingServer(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 42]);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturn($restrictions);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturn($queryBuilder);
        $queryBuilder->method('from')->willReturn($queryBuilder);
        $queryBuilder->method('where')->willReturn($queryBuilder);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $backendUserBootstrap = $this->createMock(BackendUserBootstrap::class);
        $backendUserBootstrap->expects(self::once())
            ->method('bootstrap')
            ->with(42)
            ->willReturn($this->createStub(BackendUserAuthentication::class));

        // Throw from create() to verify bootstrap was called before server creation,
        // without blocking on StdioTransport reading from STDIN
        $mcpServerFactory = $this->createMock(McpServerFactory::class);
        $mcpServerFactory->expects(self::once())
            ->method('create')
            ->willThrowException(new \RuntimeException('Server creation intercepted'));

        $command = new McpServerCommand($connectionPool, $backendUserBootstrap, $mcpServerFactory, new NullLogger());

        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server creation intercepted');

        $command->run($input, $output);
    }

    public function testExecuteUsesCustomUsername(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturn($restrictions);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturn($queryBuilder);
        $queryBuilder->method('from')->willReturn($queryBuilder);
        $queryBuilder->method('where')->willReturn($queryBuilder);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':param');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $command = new McpServerCommand(
            $connectionPool,
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            new NullLogger(),
        );

        $input = new ArrayInput(['--user' => 'editor']);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('editor', $output->fetch());
    }
}
