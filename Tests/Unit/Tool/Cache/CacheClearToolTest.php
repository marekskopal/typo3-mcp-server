<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Cache;

use MarekSkopal\MsMcpServer\Service\CacheService;
use MarekSkopal\MsMcpServer\Tool\Cache\CacheClearTool;
use MarekSkopal\MsMcpServer\Tool\Result\CacheClearedResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(CacheClearTool::class)]
final class CacheClearToolTest extends TestCase
{
    public function testExecutePagesScopeClearsPageCaches(): void
    {
        $cacheService = $this->createMock(CacheService::class);
        $cacheService->expects(self::once())->method('flushPageCaches');

        $tool = new CacheClearTool($cacheService, new NullLogger());
        $result = $tool->execute('pages');

        self::assertInstanceOf(CacheClearedResult::class, $result);
        self::assertSame('pages', $result->scope);
        self::assertTrue($result->cleared);
    }

    public function testExecuteDefaultScopeClearsPageCaches(): void
    {
        $cacheService = $this->createMock(CacheService::class);
        $cacheService->expects(self::once())->method('flushPageCaches');

        $tool = new CacheClearTool($cacheService, new NullLogger());
        $result = $tool->execute();

        self::assertInstanceOf(CacheClearedResult::class, $result);
        self::assertSame('pages', $result->scope);
    }

    public function testExecuteAllScopeClearsAllCaches(): void
    {
        $cacheService = $this->createMock(CacheService::class);
        $cacheService->expects(self::once())->method('flushAllCaches');

        $tool = new CacheClearTool($cacheService, new NullLogger());
        $result = $tool->execute('all');

        self::assertInstanceOf(CacheClearedResult::class, $result);
        self::assertSame('all', $result->scope);
    }

    public function testExecutePageScopeClearsSpecificPage(): void
    {
        $cacheService = $this->createMock(CacheService::class);
        $cacheService->expects(self::once())->method('flushPageCache')->with(42);

        $tool = new CacheClearTool($cacheService, new NullLogger());
        $result = $tool->execute('page', 42);

        self::assertInstanceOf(CacheClearedResult::class, $result);
        self::assertSame('page', $result->scope);
    }

    public function testExecutePageScopeReturnsErrorWhenPageIdMissing(): void
    {
        $cacheService = $this->createStub(CacheService::class);

        $tool = new CacheClearTool($cacheService, new NullLogger());
        $result = $tool->execute('page');

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('pageId is required', $result->error);
    }

    public function testExecuteInvalidScopeReturnsError(): void
    {
        $cacheService = $this->createStub(CacheService::class);

        $tool = new CacheClearTool($cacheService, new NullLogger());
        $result = $tool->execute('invalid');

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('Invalid scope', $result->error);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $cacheService = $this->createMock(CacheService::class);
        $cacheService->expects(self::once())
            ->method('flushPageCaches')
            ->willThrowException(new \RuntimeException('Cache flush failed'));

        $tool = new CacheClearTool($cacheService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Cache flush failed');

        $tool->execute('pages');
    }
}
