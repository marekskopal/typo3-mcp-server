<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentMoveTool;
use MarekSkopal\MsMcpServer\Tool\Result\RecordMovedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ContentMoveTool::class)]
final class ContentMoveToolTest extends TestCase
{
    public function testExecuteMovesContentToPageTop(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->with('tt_content', 42, 10);

        $tool = new ContentMoveTool($dataHandlerService, new NullLogger());
        $result = $tool->execute(42, 10);

        self::assertInstanceOf(RecordMovedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(10, $result->target);
    }

    public function testExecuteMovesContentAfterAnotherElement(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->with('tt_content', 42, -5);

        $tool = new ContentMoveTool($dataHandlerService, new NullLogger());
        $result = $tool->execute(42, -5);

        self::assertInstanceOf(RecordMovedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(-5, $result->target);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentMoveTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, 10);
    }
}
