<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ContentDeleteTool::class)]
final class ContentDeleteToolTest extends TestCase
{
    public function testExecuteDeletesContentAndReturnsResult(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('tt_content', 42);

        $tool = new ContentDeleteTool($dataHandlerService, new NullLogger());
        $result = $tool->execute(42);

        self::assertInstanceOf(RecordDeletedResult::class, $result);
        self::assertSame(42, $result->uid);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentDeleteTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1);
    }
}
