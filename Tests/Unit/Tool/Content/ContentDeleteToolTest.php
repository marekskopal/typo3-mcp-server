<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentDeleteTool::class)]
final class ContentDeleteToolTest extends TestCase
{
    public function testDryRunReportsTheDeleteWithoutPerformingIt(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 5]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('deleteRecord');

        $tool = new ContentDeleteTool($dataHandlerService, $recordService);
        $result = $tool->execute(5, dryRun: true);

        self::assertInstanceOf(RecordDeletedResult::class, $result);
        self::assertTrue($result->dryRun);
        // A client that only reads `deleted` must not mistake the preview for a real deletion.
        self::assertFalse($result->deleted);
    }

    /** Answering "would delete" for a uid the caller cannot even read would be a useless preview. */
    public function testDryRunReportsAnUnreachableRecord(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('deleteRecord');

        $tool = new ContentDeleteTool($dataHandlerService, $recordService);
        $result = $tool->execute(999, dryRun: true);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('not found or not accessible: 999', $result->error);
    }

    public function testExecuteDeletesContentAndReturnsResult(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('tt_content', 42);

        $tool = new ContentDeleteTool($dataHandlerService, $this->createStub(RecordService::class));
        $result = $tool->execute(42);

        self::assertInstanceOf(RecordDeletedResult::class, $result);
        self::assertSame(42, $result->uid);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentDeleteTool($dataHandlerService, $this->createStub(RecordService::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1);
    }
}
