<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\News;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\News\NewsDeleteTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(NewsDeleteTool::class)]
final class NewsDeleteToolTest extends TestCase
{
    public function testExecuteDeletesNewsAndReturnsJson(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('tx_news_domain_model_news', 5);

        $tool = new NewsDeleteTool($dataHandlerService, new NullLogger());
        $result = json_decode($tool->execute(5), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5, $result['uid']);
        self::assertSame(true, $result['deleted']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->willThrowException(new \RuntimeException('Delete failed'));

        $tool = new NewsDeleteTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Delete failed');

        $tool->execute(5);
    }
}
