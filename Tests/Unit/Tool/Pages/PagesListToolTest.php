<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesListTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PagesListTool::class)]
final class PagesListToolTest extends TestCase
{
    public function testExecuteCallsRecordServiceWithCorrectTableAndFields(): void
    {
        $expectedResult = [
            'records' => [['uid' => 1, 'title' => 'Root Page']],
            'total' => 1,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'pages',
                0,
                20,
                0,
                ['uid', 'pid', 'title', 'slug', 'doktype', 'hidden', 'sorting', 'sys_language_uid', 'l10n_parent'],
            )
            ->willReturn($expectedResult);

        $tool = new PagesListTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('Root Page', $result['records'][0]['title']);
    }

    public function testExecutePassesPaginationParameters(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'pages',
                5,
                10,
                30,
                self::anything(),
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new PagesListTool($recordService, new NullLogger());
        $tool->execute(5, 10, 30);
    }

    public function testExecutePassesLanguageFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'pages',
                0,
                20,
                0,
                self::anything(),
                0,
                'sys_language_uid',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new PagesListTool($recordService, new NullLogger());
        $tool->execute(0, 20, 0, 0);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->willThrowException(new \RuntimeException('Database error'));

        $tool = new PagesListTool($recordService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute();
    }
}
