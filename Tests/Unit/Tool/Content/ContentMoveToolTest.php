<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentMoveTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

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
        $result = json_decode($tool->execute(42, 10), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertTrue($result['moved']);
        self::assertSame(10, $result['target']);
    }

    public function testExecuteMovesContentAfterAnotherElement(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->with('tt_content', 42, -5);

        $tool = new ContentMoveTool($dataHandlerService, new NullLogger());
        $result = json_decode($tool->execute(42, -5), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertTrue($result['moved']);
        self::assertSame(-5, $result['target']);
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
