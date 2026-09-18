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

    /**
     * Nearly every write tool takes its payload as a JSON *string*, so `is_string()` used to keep
     * the first 100 characters of it — record content, and potentially personal data, copied into
     * a table every administrator can read. Only the field names are recorded now.
     */
    public function testLogSuccessReducesJsonObjectArgumentsToFieldNames(): void
    {
        $fields = json_encode(
            ['header' => 'Contact us', 'bodytext' => 'Reach Jane at jane@example.com', 'hidden' => 0],
            JSON_THROW_ON_ERROR,
        );

        $args = $this->captureLoggedArguments([42, $fields]);

        self::assertSame([42, '{header, bodytext, hidden}'], $args);
        self::assertStringNotContainsString('jane@example.com', json_encode($args, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function stringArgumentProvider(): iterable
    {
        yield 'table name kept as it is' => ['tt_content', 'tt_content'];
        yield 'plain-text search term kept' => ['Brio', 'Brio'];
        yield 'uid list kept' => ['1,2,3', '1,2,3'];
        yield 'JSON list carries no field content' => ['[1,2,3]', '[1,2,3]'];
        yield 'search condition reduced to its field' => ['{"title":{"like":"secret"}}', '{title}'];
        yield 'empty object' => ['{}', '{}'];
        yield 'whitespace around the object' => ['  {"title":"x"}  ', '{title}'];
        yield 'malformed JSON kept so the failure stays diagnosable' => ['{"title":', '{"title":'];
        yield 'control characters stripped from field names' => ["{\"ti\\u0000tle\":\"x\"}", '{title}'];
    }

    #[DataProvider('stringArgumentProvider')]
    public function testStringArgumentsAreRedactedByShape(string $argument, string $expected): void
    {
        self::assertSame([$expected], $this->captureLoggedArguments([$argument]));
    }

    public function testLongValuesAndWideObjectsStayBounded(): void
    {
        $longTerm = str_repeat('a', 300);
        self::assertSame(100, mb_strlen((string) $this->captureLoggedArguments([$longTerm])[0]));

        $manyFields = [];
        for ($i = 0; $i < 40; $i++) {
            $manyFields['field_' . $i] = 'value';
        }

        $logged = (string) $this->captureLoggedArguments([json_encode($manyFields, JSON_THROW_ON_ERROR)])[0];
        self::assertStringStartsWith('{field_0, field_1,', $logged);
        self::assertLessThanOrEqual(100, mb_strlen($logged));
        self::assertStringNotContainsString('value', $logged);
    }

    /**
     * Runs one successful invocation and returns what landed in `log_data.args`.
     *
     * @param list<mixed> $arguments
     * @return list<mixed>
     */
    private function captureLoggedArguments(array $arguments): array
    {
        $captured = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->willReturnCallback(static function (string $table, array $data) use (&$captured): int {
                /** @var array{args?: list<mixed>} $logData */
                $logData = json_decode((string) $data['log_data'], true, 512, JSON_THROW_ON_ERROR);
                $captured = $logData['args'] ?? [];

                return 1;
            });

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->createAuditLogger($connectionPool, new NullLogger())
            ->logSuccess('ContentUpdateTool', 'tool', $arguments, 5);

        return $captured;
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
