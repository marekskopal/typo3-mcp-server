<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesCopyTool;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCopiedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PagesCopyTool::class)]
final class PagesCopyToolTest extends TestCase
{
    public function testExecuteCopiesPageAndReturnsResult(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('pages', 42, 10, 0)
            ->willReturn(100);

        $tool = new PagesCopyTool($dataHandlerService, new NullLogger());
        $result = $tool->execute(42, 10);

        self::assertInstanceOf(RecordCopiedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(100, $result->newUid);
        self::assertTrue($result->copied);
    }

    public function testExecuteCopiesPageAfterAnotherPage(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('pages', 42, -5, 0)
            ->willReturn(101);

        $tool = new PagesCopyTool($dataHandlerService, new NullLogger());
        $result = $tool->execute(42, -5);

        self::assertInstanceOf(RecordCopiedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(101, $result->newUid);
    }

    public function testExecuteCopiesPageWithSubpages(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('pages', 42, 10, 99)
            ->willReturn(102);

        $tool = new PagesCopyTool($dataHandlerService, new NullLogger());
        $result = $tool->execute(42, 10, true);

        self::assertInstanceOf(RecordCopiedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(102, $result->newUid);
    }

    public function testExecuteWithoutSubpagesPassesZeroDepth(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('pages', 42, 10, 0)
            ->willReturn(103);

        $tool = new PagesCopyTool($dataHandlerService, new NullLogger());
        $tool->execute(42, 10, false);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new PagesCopyTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, 10);
    }
}
