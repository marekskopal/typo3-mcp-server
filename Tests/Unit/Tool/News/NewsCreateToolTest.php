<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\News;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\News\NewsCreateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(NewsCreateTool::class)]
final class NewsCreateToolTest extends TestCase
{
    public function testExecuteCreatesNewsWithRequiredFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tx_news_domain_model_news',
                10,
                [
                    'title' => 'Test News',
                    'teaser' => '',
                    'bodytext' => '',
                    'hidden' => 0,
                ],
            )
            ->willReturn(42);

        $tool = new NewsCreateTool($dataHandlerService, new NullLogger());
        $result = json_decode($tool->execute(10, 'Test News'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertSame('Test News', $result['title']);
    }

    public function testExecuteCreatesNewsWithAllOptionalFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tx_news_domain_model_news',
                10,
                [
                    'title' => 'Full News',
                    'teaser' => 'A teaser',
                    'bodytext' => 'Body content',
                    'hidden' => 1,
                    'datetime' => strtotime('2025-01-15 12:00:00'),
                    'author' => 'Jane Doe',
                    'author_email' => 'jane@example.com',
                    'path_segment' => 'full-news',
                ],
            )
            ->willReturn(43);

        $tool = new NewsCreateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(
                10,
                'Full News',
                'A teaser',
                'Body content',
                '2025-01-15 12:00:00',
                true,
                'Jane Doe',
                'jane@example.com',
                'full-news',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(43, $result['uid']);
        self::assertSame('Full News', $result['title']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new NewsCreateTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(10, 'Test News');
    }
}
