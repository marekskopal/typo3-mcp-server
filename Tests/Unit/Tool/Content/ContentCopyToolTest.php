<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCopyTool;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCopiedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentCopyTool::class)]
final class ContentCopyToolTest extends TestCase
{
    public function testExecuteCopiesContentToPageAndReturnsResult(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('tt_content', 42, 10)
            ->willReturn(100);

        $tool = new ContentCopyTool($dataHandlerService);
        $result = $tool->execute(42, 10);

        self::assertInstanceOf(RecordCopiedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(100, $result->newUid);
        self::assertTrue($result->copied);
    }

    public function testExecuteCopiesContentAfterAnotherElement(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('copyRecord')
            ->with('tt_content', 42, -5)
            ->willReturn(101);

        $tool = new ContentCopyTool($dataHandlerService);
        $result = $tool->execute(42, -5);

        self::assertInstanceOf(RecordCopiedResult::class, $result);
        self::assertSame(42, $result->uid);
        self::assertSame(101, $result->newUid);
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

        $tool->execute(1, 10);
    }
}
