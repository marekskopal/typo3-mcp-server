<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Server\ErrorHandlingProxy;
use Mcp\Exception\PromptGetException;
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

    public function testPromptTypeConvertsUnexpectedExceptionToPromptGetException(): void
    {
        $inner = new class () {
            public function execute(): never
            {
                throw new \RuntimeException('SQLSTATE near "SELECT * FROM be_users"');
            }
        };

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('logFailure');

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $auditLogger, 'prompt');

        try {
            $proxy->execute();
            self::fail('Expected PromptGetException');
        } catch (PromptGetException $e) {
            self::assertStringNotContainsString('be_users', $e->getMessage());
            self::assertStringContainsString('internal error', $e->getMessage());
        }
    }

    public function testPromptTypePreservesDeliberatePromptGetExceptionAndAuditsIt(): void
    {
        $inner = new class () {
            public function execute(): never
            {
                throw new PromptGetException('Prompt argument missing');
            }
        };

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('logFailure')
            ->with(self::matchesRegularExpression('/^.+$/'), 'prompt', [], self::greaterThanOrEqual(0), 'Prompt argument missing');

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $auditLogger, 'prompt');

        $this->expectException(PromptGetException::class);
        $this->expectExceptionMessage('Prompt argument missing');
        $proxy->execute();
    }

    public function testUnexpectedExceptionMessageIsNotLeakedToClient(): void
    {
        $inner = new class () {
            public function execute(): never
            {
                throw new \RuntimeException('SQLSTATE near "SELECT * FROM be_users" at /var/www/app');
            }
        };

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $this->createStub(AuditLogger::class), 'tool');

        try {
            $proxy->execute();
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringNotContainsString('be_users', $e->getMessage());
            self::assertStringNotContainsString('/var/www', $e->getMessage());
            self::assertStringContainsString('internal error', $e->getMessage());
        }
    }

    public function testDeliberateToolCallExceptionMessageIsPreserved(): void
    {
        $inner = new class () {
            public function execute(): never
            {
                throw new ToolCallException('None of the provided UIDs exist in table pages');
            }
        };

        $proxy = new ErrorHandlingProxy($inner, new NullLogger(), $this->createStub(AuditLogger::class), 'tool');

        try {
            $proxy->execute();
            self::fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            self::assertSame('None of the provided UIDs exist in table pages', $e->getMessage());
        }
    }
}
