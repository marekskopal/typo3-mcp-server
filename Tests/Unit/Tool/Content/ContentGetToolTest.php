<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentGetTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentGetTool::class)]
final class ContentGetToolTest extends TestCase
{
    public function testExecuteReturnsContentWhenFound(): void
    {
        $expectedRecord = [
            'uid' => 42,
            'pid' => 10,
            'CType' => 'text',
            'header' => 'Test Header',
            'bodytext' => 'Test body',
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with(
                'tt_content',
                42,
                [
                    'uid',
                    'pid',
                    'CType',
                    'header',
                    'header_layout',
                    'bodytext',
                    'hidden',
                    'sorting',
                    'colPos',
                    'sys_language_uid',
                    'fe_group',
                    'subheader',
                    'image',
                    'media',
                    'list_type',
                    'pi_flexform',
                ],
            )
            ->willReturn($expectedRecord);

        $tool = new ContentGetTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(42), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertSame('Test Header', $result['header']);
    }

    public function testExecuteReturnsErrorWhenNotFound(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('tt_content', 999, self::anything())
            ->willReturn(null);

        $tool = new ContentGetTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(999), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Content element not found', $result['error']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->willThrowException(new \RuntimeException('Database error'));

        $tool = new ContentGetTool($recordService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute(1);
    }
}
