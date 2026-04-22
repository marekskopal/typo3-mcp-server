<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PagesDeleteTool::class)]
final class PagesDeleteToolTest extends TestCase
{
    public function testExecuteDeletesPageAndReturnsResult(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('pages', 42);

        $tool = new PagesDeleteTool($dataHandlerService, new NullLogger());
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

        $tool = new PagesDeleteTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1);
    }
}
