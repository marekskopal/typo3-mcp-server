<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesGetTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PagesGetTool::class)]
final class PagesGetToolTest extends TestCase
{
    public function testExecuteReturnsPageWhenFound(): void
    {
        $expectedRecord = [
            'uid' => 42,
            'pid' => 1,
            'title' => 'Test Page',
            'slug' => '/test-page',
            'doktype' => 1,
            'hidden' => 0,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with(
                'pages',
                42,
                [
                    'uid',
                    'pid',
                    'title',
                    'slug',
                    'doktype',
                    'hidden',
                    'sorting',
                    'nav_title',
                    'subtitle',
                    'abstract',
                    'description',
                    'no_cache',
                    'fe_group',
                    'layout',
                    'backend_layout',
                ],
            )
            ->willReturn($expectedRecord);

        $tool = new PagesGetTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(42), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertSame('Test Page', $result['title']);
    }

    public function testExecuteReturnsErrorWhenNotFound(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('pages', 999, self::anything())
            ->willReturn(null);

        $tool = new PagesGetTool($recordService, new NullLogger());
        $result = json_decode($tool->execute(999), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Page not found', $result['error']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->willThrowException(new \RuntimeException('Database error'));

        $tool = new PagesGetTool($recordService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute(1);
    }
}
