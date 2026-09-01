<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Logging;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(AuditLogger::class)]
final class AuditLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->method('getUserId')->willReturn(1);
        $backendUser->workspace = 0;

        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['EXEC_TIME'] = 1700000000;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['EXEC_TIME']);
    }

    public function testLogSuccessWritesToSysLog(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'sys_log',
                self::callback(static function (array $data): bool {
                    self::assertSame(1, $data['userid']);
                    self::assertSame(4, $data['type']);
                    self::assertSame(0, $data['error']);
                    self::assertStringContainsString('OK', $data['details']);
                    self::assertStringContainsString('PagesListTool', $data['details']);

                    $logData = json_decode($data['log_data'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame('PagesListTool', $logData['tool']);
                    self::assertSame('tool', $logData['type']);
                    self::assertSame(42, $logData['executionTimeMs']);

                    return true;
                }),
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = $this->createAuditLogger($connectionPool, new NullLogger());
        $auditLogger->logSuccess('PagesListTool', 'tool', [0, 20, 0], 42);
    }

    public function testLogFailureWritesToSysLogWithError(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'sys_log',
                self::callback(static function (array $data): bool {
                    self::assertSame(2, $data['error']);
                    self::assertSame('error', $data['level']);
                    self::assertStringContainsString('failed', $data['details']);
                    // The raw error message must not be interpolated into the format-string details.
                    self::assertStringNotContainsString('Record not found', $data['details']);

                    $logData = json_decode($data['log_data'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame('Record not found', $logData['error']);

                    return true;
                }),
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = $this->createAuditLogger($connectionPool, new NullLogger());
        $auditLogger->logFailure('PagesDeleteTool', 'tool', [42], 12, 'Record not found');
    }

    public function testLogSuccessRecordsScalarArgumentsButOmitsArrays(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'sys_log',
                self::callback(static function (array $data): bool {
                    $logData = json_decode($data['log_data'], true, 512, JSON_THROW_ON_ERROR);
                    // Scalars (uid, table name) are kept; the field-payload array is dropped.
                    self::assertSame([42, 'tt_content'], $logData['args']);

                    return true;
                }),
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = $this->createAuditLogger($connectionPool, new NullLogger());
        $auditLogger->logSuccess('RecordUpdateTool', 'tool', [42, 'tt_content', ['title' => 'secret payload']], 5);
    }

    public function testLogFailureIsReportedToPsrLoggerWhenDatabaseFails(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('Database connection lost'));

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('audit log write failed'), self::anything());

        $auditLogger = $this->createAuditLogger($connectionPool, $logger);
        $auditLogger->logSuccess('PagesListTool', 'tool', [], 10);
    }

    public function testLogSuccessDoesNotThrowWhenDatabaseFails(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('Database connection lost'));

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = $this->createAuditLogger($connectionPool, new NullLogger());
        $auditLogger->logSuccess('PagesListTool', 'tool', [], 10);

        self::assertTrue(true);
    }

    public function testLogSuccessSkipsWhenNoBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = $this->createAuditLogger($connectionPool, new NullLogger());
        $auditLogger->logSuccess('PagesListTool', 'tool', [], 10);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool, 3: bool}> */
    public static function levelProvider(): iterable
    {
        // level, handler, expect a row for a successful call, expect a row for a failed call
        yield 'all logs a read' => ['all', 'PagesListTool', true, true];
        yield 'all logs a write' => ['all', 'PagesCreateTool', true, true];
        yield 'mutations drops a successful read' => ['mutations', 'PagesListTool', false, true];
        yield 'mutations keeps a write' => ['mutations', 'PagesCreateTool', true, true];
        yield 'errors drops both successes' => ['errors', 'PagesCreateTool', false, true];
        yield 'off drops everything' => ['off', 'PagesCreateTool', false, false];
        // An unreadable setting must not silently disable the trail.
        yield 'unknown value falls back to the default' => ['nonsense', 'PagesListTool', false, true];
    }

    #[DataProvider('levelProvider')]
    public function testLevelDecidesWhatReachesSysLog(
        string $level,
        string $handler,
        bool $expectSuccessRow,
        bool $expectFailureRow,
    ): void {
        foreach ([[false, $expectSuccessRow], [true, $expectFailureRow]] as [$isFailure, $expectRow]) {
            $connection = $this->createMock(Connection::class);
            $connection->expects($expectRow ? self::once() : self::never())->method('insert');

            $connectionPool = $this->createStub(ConnectionPool::class);
            $connectionPool->method('getConnectionForTable')->willReturn($connection);

            $auditLogger = $this->createAuditLogger($connectionPool, new NullLogger(), $level);

            if ($isFailure) {
                $auditLogger->logFailure($handler, 'tool', [1], 5, 'boom');
            } else {
                $auditLogger->logSuccess($handler, 'tool', [1], 5);
            }
        }
    }

    /** A failed read stays in the trail: rare, diagnostic, and not the source of the volume problem. */
    public function testMutationsLevelKeepsFailedReads(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->createAuditLogger($connectionPool, new NullLogger(), 'mutations')
            ->logFailure('RecordSearchTool', 'tool', ['pages'], 5, 'boom');
    }

    /** Registrar tools report an MCP tool name, not a class name — both conventions must be graded. */
    public function testMutationsLevelGradesRegistrarToolNames(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert');

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = $this->createAuditLogger($connectionPool, new NullLogger(), 'mutations');
        $auditLogger->logSuccess('item_list', 'tool', [0], 5);
        $auditLogger->logSuccess('item_delete', 'tool', [1], 5);
    }

    /** Existing cases exercise the write mechanics, so they pin the level rather than inherit the default. */
    private function createAuditLogger(
        ConnectionPool $connectionPool,
        LoggerInterface $logger,
        string $level = 'all',
    ): AuditLogger {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['auditLogLevel' => $level]);

        return new AuditLogger($connectionPool, $logger, $extensionConfiguration);
    }
}
