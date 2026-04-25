<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;

use MarekSkopal\MsMcpServer\Tool\Batch\RecordDeleteBatchTool;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsDeletedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordDeleteBatchTool::class)]
final class RecordDeleteBatchToolTest extends TestCase
{
    public function testExecuteDeletesMultipleRecords(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecords')
            ->with('pages', [1, 2, 3]);

        $tool = new RecordDeleteBatchTool($dataHandlerService);
        $result = $tool->execute('pages', '1,2,3');

        self::assertInstanceOf(BatchRecordsDeletedResult::class, $result);
        self::assertSame([1, 2, 3], $result->uids);
        self::assertSame(3, $result->count);
    }

    public function testExecuteHandlesSingleUid(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecords')
            ->with('pages', [42]);

        $tool = new RecordDeleteBatchTool($dataHandlerService);
        $result = $tool->execute('pages', '42');

        self::assertSame(1, $result->count);
    }

    public function testExecuteThrowsOnDataHandlerError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('deleteRecords')
            ->willThrowException(new \RuntimeException('DataHandler errors: Record not found'));

        $tool = new RecordDeleteBatchTool($dataHandlerService);

        $this->expectException(\RuntimeException::class);
        $tool->execute('pages', '1,2');
    }
}
