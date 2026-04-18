<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentListTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentListTool::class)]
final class ContentListToolTest extends TestCase
{
    public function testExecuteCallsRecordServiceWithCorrectTableAndFields(): void
    {
        $expectedResult = [
            'records' => [['uid' => 1, 'header' => 'Test Content']],
            'total' => 1,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                10,
                20,
                0,
                ['uid', 'pid', 'CType', 'header', 'bodytext', 'hidden', 'sorting', 'colPos', 'sys_language_uid', 'list_type'],
            )
            ->willReturn($expectedResult);

        $tool = new ContentListTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(10), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('Test Content', $result['records'][0]['header']);
    }

    public function testExecutePassesPaginationParameters(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                5,
                10,
                30,
                self::anything(),
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentListTool($recordService, new NullLogger());
        $tool->execute(5, 10, 30);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->willThrowException(new \RuntimeException('Database error'));

        $tool = new ContentListTool($recordService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute(1);
    }
}
