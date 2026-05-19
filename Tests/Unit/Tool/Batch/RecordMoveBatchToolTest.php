<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Batch\RecordMoveBatchTool;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsMovedResult;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordMoveBatchTool::class)]
final class RecordMoveBatchToolTest extends TestCase
{
    public function testExecuteMovesMultipleRecordsToTargetPage(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([10, 20, 30]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecords')
            ->with('tt_content', [10, 20, 30], 5);

        $tool = new RecordMoveBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('tt_content', '10,20,30', targetPid: 5);

        self::assertInstanceOf(BatchRecordsMovedResult::class, $result);
        self::assertSame([10, 20, 30], $result->uids);
        self::assertSame(3, $result->count);
        self::assertSame(5, $result->target);
        self::assertSame([], $result->skippedUids);
    }

    public function testExecuteMovesRecordsAfterSiblingViaAfterUid(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([10]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecords')
            ->with('tt_content', [10], -42);

        $tool = new RecordMoveBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('tt_content', '10', afterUid: 42);

        self::assertInstanceOf(BatchRecordsMovedResult::class, $result);
        self::assertSame(-42, $result->target);
    }

    public function testExecuteReturnsErrorWhenNeitherTargetGiven(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('moveRecords');

        $tool = new RecordMoveBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('tt_content', '10,20');

        self::assertInstanceOf(ErrorResult::class, $result);
    }

    public function testExecuteReturnsErrorWhenBothTargetsGiven(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('moveRecords');

        $tool = new RecordMoveBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('tt_content', '10,20', targetPid: 5, afterUid: 42);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('not both', $result->error);
    }

    public function testExecuteThrowsOnDataHandlerError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([10, 20]);

        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('moveRecords')
            ->willThrowException(new \RuntimeException('DataHandler errors: Access denied'));

        $tool = new RecordMoveBatchTool($dataHandlerService, $recordService);

        $this->expectException(\RuntimeException::class);
        $tool->execute('tt_content', '10,20', targetPid: 5);
    }

    public function testExecuteSkipsNonExistentUids(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([10, 30]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecords')
            ->with('tt_content', [10, 30], 5);

        $tool = new RecordMoveBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('tt_content', '10,20,30', targetPid: 5);

        self::assertInstanceOf(BatchRecordsMovedResult::class, $result);
        self::assertSame([10, 30], $result->uids);
        self::assertSame(2, $result->count);
        self::assertSame([20], $result->skippedUids);
    }

    public function testExecuteThrowsWhenNoUidsExist(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([]);

        $dataHandlerService = $this->createStub(DataHandlerService::class);

        $tool = new RecordMoveBatchTool($dataHandlerService, $recordService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('None of the provided UIDs exist');

        $tool->execute('tt_content', '10,20', targetPid: 5);
    }
}
