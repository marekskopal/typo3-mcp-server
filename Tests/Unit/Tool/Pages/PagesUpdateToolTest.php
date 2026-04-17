<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesUpdateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PagesUpdateTool::class)]
final class PagesUpdateToolTest extends TestCase
{
    public function testExecuteUpdatesWithValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'pages',
                42,
                ['title' => 'New Title', 'slug' => '/new'],
            );

        $tool = new PagesUpdateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(42, json_encode(['title' => 'New Title', 'slug' => '/new'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(42, $result['uid']);
        self::assertSame(['title', 'slug'], $result['updated']);
    }

    public function testExecuteFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'pages',
                10,
                ['title' => 'T'],
            );

        $tool = new PagesUpdateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(10, json_encode(['title' => 'T', 'invalid_field' => 'x'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(10, $result['uid']);
        self::assertSame(['title'], $result['updated']);
    }

    public function testExecuteReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())
            ->method('updateRecord');

        $tool = new PagesUpdateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(1, json_encode(['bad_field' => 'x'], JSON_THROW_ON_ERROR)),
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

        $tool = new PagesUpdateTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR));
    }
}
