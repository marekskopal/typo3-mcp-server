<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Batch\RecordDeleteBatchTool;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsDeletedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordDeleteBatchTool::class)]
final class RecordDeleteBatchToolTest extends TestCase
{
    public function testExecuteDeletesMultipleRecords(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1, 2, 3]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecords')
            ->with('pages', [1, 2, 3]);

        $tool = new RecordDeleteBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('pages', '1,2,3');

        self::assertInstanceOf(BatchRecordsDeletedResult::class, $result);
        self::assertSame([1, 2, 3], $result->uids);
        self::assertSame(3, $result->count);
        self::assertSame([], $result->skippedUids);
    }

    public function testExecuteHandlesSingleUid(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([42]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecords')
            ->with('pages', [42]);

        $tool = new RecordDeleteBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('pages', '42');

        self::assertSame(1, $result->count);
    }

    public function testExecuteThrowsOnDataHandlerError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1, 2]);

        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('deleteRecords')
            ->willThrowException(new \RuntimeException('DataHandler errors: Record not found'));

        $tool = new RecordDeleteBatchTool($dataHandlerService, $recordService);

        $this->expectException(\RuntimeException::class);
        $tool->execute('pages', '1,2');
    }

    public function testExecuteSkipsNonExistentUids(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1, 3]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecords')
            ->with('pages', [1, 3]);

        $tool = new RecordDeleteBatchTool($dataHandlerService, $recordService);
        $result = $tool->execute('pages', '1,2,3');

        self::assertSame([1, 3], $result->uids);
        self::assertSame(2, $result->count);
        self::assertSame([2], $result->skippedUids);
    }

    public function testExecuteThrowsWhenNoUidsExist(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([]);

        $dataHandlerService = $this->createStub(DataHandlerService::class);

        $tool = new RecordDeleteBatchTool($dataHandlerService, $recordService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('None of the provided UIDs exist');

        $tool->execute('pages', '1,2,3');
    }
}
