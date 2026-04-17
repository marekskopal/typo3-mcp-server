<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\News;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\News\NewsGetTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(NewsGetTool::class)]
final class NewsGetToolTest extends TestCase
{
    public function testExecuteReturnsNewsWhenFound(): void
    {
        $record = [
            'uid' => 1,
            'pid' => 10,
            'title' => 'Test News',
            'teaser' => 'A teaser',
            'bodytext' => 'Body text',
            'datetime' => 1700000000,
            'hidden' => 0,
            'categories' => 0,
            'author' => 'John Doe',
            'author_email' => 'john@example.com',
            'path_segment' => 'test-news',
            'type' => 0,
            'keywords' => '',
            'description' => '',
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with(
                'tx_news_domain_model_news',
                1,
                ['uid', 'pid', 'title', 'teaser', 'bodytext', 'datetime', 'hidden', 'categories', 'author', 'author_email', 'path_segment', 'type', 'keywords', 'description'],
            )
            ->willReturn($record);

        $tool = new NewsGetTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(1), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['uid']);
        self::assertSame('Test News', $result['title']);
        self::assertSame('John Doe', $result['author']);
    }

    public function testExecuteReturnsErrorWhenNotFound(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with(
                'tx_news_domain_model_news',
                999,
                self::anything(),
            )
            ->willReturn(null);

        $tool = new NewsGetTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(999), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('News record not found', $result['error']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->willThrowException(new \RuntimeException('DB error'));

        $tool = new NewsGetTool($recordService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DB error');

        $tool->execute(1);
    }
}
