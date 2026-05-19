<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCopyTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCopiedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentCopyTool::class)]
final class ContentCopyToolTest extends TestCase
{
    public function testExecuteCopiesContentToTopOfTargetPage(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('tt_content', 42, 10)
            ->willReturn(100);

        $tool = new ContentCopyTool($dataHandlerService);
        $result = $tool->execute(42, targetPid: 10);

        self::assertInstanceOf(RecordCopiedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(100, $result->newUid);
        self::assertTrue($result->copied);
    }

    public function testExecuteCopiesContentAfterSiblingViaAfterUid(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('tt_content', 42, -5)
            ->willReturn(101);

        $tool = new ContentCopyTool($dataHandlerService);
        $result = $tool->execute(42, afterUid: 5);

        self::assertInstanceOf(RecordCopiedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(101, $result->newUid);
    }

    public function testExecuteReturnsErrorWhenNeitherTargetGiven(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('copyRecord');

        $tool = new ContentCopyTool($dataHandlerService);
        $result = $tool->execute(42);

        self::assertInstanceOf(ErrorResult::class, $result);
    }

    public function testExecuteReturnsErrorWhenBothTargetsGiven(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('copyRecord');

        $tool = new ContentCopyTool($dataHandlerService);
        $result = $tool->execute(42, targetPid: 10, afterUid: 5);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('not both', $result->error);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentCopyTool($dataHandlerService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, targetPid: 10);
    }
}
