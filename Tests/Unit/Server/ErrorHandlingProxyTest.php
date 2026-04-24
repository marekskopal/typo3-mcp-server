<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Server\ErrorHandlingProxy;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ErrorHandlingProxy::class)]
final class ErrorHandlingProxyTest extends TestCase
{
    public function testCallLogsSuccessToAuditLogger(): void
    {
        $inner = new class () {
            public function execute(int $uid): string
            {
                return 'result';
            }
        };

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('logSuccess')
            ->with(
                self::matchesRegularExpression('/^.+$/'),
                'tool',
                [42],
                self::greaterThanOrEqual(0),
            );

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $auditLogger, 'tool');
        $result = $proxy->execute(42);

        self::assertSame('result', $result);
    }

    public function testCallLogsFailureToAuditLoggerOnException(): void
    {
        $inner = new class () {
            public function execute(): never
            {
                throw new \RuntimeException('Something went wrong');
            }
        };

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('logFailure')
            ->with(
                self::matchesRegularExpression('/^.+$/'),
                'tool',
                [],
                self::greaterThanOrEqual(0),
                'Something went wrong',
            );

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $auditLogger, 'tool');

        $this->expectException(ToolCallException::class);
        $proxy->execute();
    }

    public function testCallLogsFailureOnToolCallException(): void
    {
        $inner = new class () {
            public function execute(): never
            {
                throw new ToolCallException('Validation failed');
            }
        };

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('logFailure')
            ->with(
                self::matchesRegularExpression('/^.+$/'),
                'tool',
                [],
                self::greaterThanOrEqual(0),
                'Validation failed',
            );

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $auditLogger, 'tool');

        $this->expectException(ToolCallException::class);
        $proxy->execute();
    }
}
