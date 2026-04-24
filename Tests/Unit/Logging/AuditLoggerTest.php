<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Logging;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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

        $auditLogger = new AuditLogger($connectionPool);
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
                    self::assertStringContainsString('failed', $data['details']);
                    self::assertStringContainsString('Record not found', $data['details']);

                    $logData = json_decode($data['log_data'], true, 512, JSON_THROW_ON_ERROR);
                    self::assertSame('Record not found', $logData['error']);

                    return true;
                }),
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = new AuditLogger($connectionPool);
        $auditLogger->logFailure('PagesDeleteTool', 'tool', [42], 12, 'Record not found');
    }

    public function testLogSuccessDoesNotThrowWhenDatabaseFails(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willThrowException(new \RuntimeException('Database connection lost'));

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $auditLogger = new AuditLogger($connectionPool);
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

        $auditLogger = new AuditLogger($connectionPool);
        $auditLogger->logSuccess('PagesListTool', 'tool', [], 10);
    }
}
