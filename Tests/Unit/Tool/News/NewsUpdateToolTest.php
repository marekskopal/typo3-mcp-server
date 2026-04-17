<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\News;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\News\NewsUpdateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(NewsUpdateTool::class)]
final class NewsUpdateToolTest extends TestCase
{
    public function testExecuteUpdatesWithValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'tx_news_domain_model_news',
                1,
                ['title' => 'Updated Title', 'teaser' => 'Updated Teaser'],
            );

        $tool = new NewsUpdateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(1, json_encode(['title' => 'Updated Title', 'teaser' => 'Updated Teaser'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $result['uid']);
        self::assertSame(['title', 'teaser'], $result['updated']);
    }

    public function testExecuteFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'tx_news_domain_model_news',
                1,
                ['title' => 'Valid'],
            );

        $tool = new NewsUpdateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(1, json_encode(['title' => 'Valid', 'invalid_field' => 'ignored'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $result['uid']);
        self::assertSame(['title'], $result['updated']);
    }

    public function testExecuteReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())
            ->method('updateRecord');

        $tool = new NewsUpdateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(1, json_encode(['invalid_field' => 'value'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('No valid fields provided', $result['error']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new NewsUpdateTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR));
    }
}
