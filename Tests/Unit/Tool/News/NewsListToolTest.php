<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\News;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\News\NewsListTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(NewsListTool::class)]
final class NewsListToolTest extends TestCase
{
    public function testExecuteCallsRecordServiceWithCorrectTableAndFields(): void
    {
        $expectedResult = [
            'records' => [['uid' => 1, 'title' => 'News 1']],
            'total' => 1,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tx_news_domain_model_news',
                10,
                20,
                0,
                ['uid', 'pid', 'title', 'teaser', 'datetime', 'hidden', 'categories'],
            )
            ->willReturn($expectedResult);

        $tool = new NewsListTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(10), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('News 1', $result['records'][0]['title']);
    }

    public function testExecutePassesPaginationParameters(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tx_news_domain_model_news',
                5,
                10,
                30,
                self::anything(),
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new NewsListTool($recordService, new NullLogger());
        $tool->execute(5, 10, 30);
    }
}
