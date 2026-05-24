<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesMoveTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordMovedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PagesMoveTool::class)]
final class PagesMoveToolTest extends TestCase
{
    public function testExecuteMovesPageAsFirstChildOfTargetPage(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->with('pages', 42, 10);

        $tool = new PagesMoveTool($dataHandlerService);
        $result = $tool->execute(42, targetPid: 10);

        self::assertInstanceOf(RecordMovedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(10, $result->target);
    }

    public function testExecuteMovesPageAfterSiblingViaAfterUid(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        // afterUid=5 → DataHandler target = -5 (move after page uid=5, same parent)
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->with('pages', 42, -5);

        $tool = new PagesMoveTool($dataHandlerService);
        $result = $tool->execute(42, afterUid: 5);

        self::assertInstanceOf(RecordMovedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(-5, $result->target);
    }

    public function testExecuteMovesPageToRoot(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->with('pages', 42, 0);

        $tool = new PagesMoveTool($dataHandlerService);
        $result = $tool->execute(42, targetPid: 0);

        self::assertInstanceOf(RecordMovedResult::class, $result);
        self::assertSame(0, $result->target);
    }

    public function testExecuteReturnsErrorWhenNeitherTargetGiven(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('moveRecord');

        $tool = new PagesMoveTool($dataHandlerService);
        $result = $tool->execute(42);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('targetPid', $result->error);
        self::assertStringContainsString('afterUid', $result->error);
    }

    public function testExecuteReturnsErrorWhenBothTargetsGiven(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('moveRecord');

        $tool = new PagesMoveTool($dataHandlerService);
        $result = $tool->execute(42, targetPid: 10, afterUid: 5);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('not both', $result->error);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new PagesMoveTool($dataHandlerService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, targetPid: 10);
    }
}
