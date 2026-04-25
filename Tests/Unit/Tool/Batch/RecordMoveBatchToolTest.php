<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Batch\RecordMoveBatchTool;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsMovedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordMoveBatchTool::class)]
final class RecordMoveBatchToolTest extends TestCase
{
    public function testExecuteMovesMultipleRecords(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecords')
            ->with('tt_content', [10, 20, 30], 5);

        $tool = new RecordMoveBatchTool($dataHandlerService);
        $result = $tool->execute('tt_content', '10,20,30', 5);

        self::assertInstanceOf(BatchRecordsMovedResult::class, $result);
        self::assertSame([10, 20, 30], $result->uids);
        self::assertSame(3, $result->count);
        self::assertSame(5, $result->target);
    }

    public function testExecuteWithNegativeTarget(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('moveRecords')
            ->with('tt_content', [10], -42);

        $tool = new RecordMoveBatchTool($dataHandlerService);
        $result = $tool->execute('tt_content', '10', -42);

        self::assertSame(-42, $result->target);
    }

    public function testExecuteThrowsOnDataHandlerError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('moveRecords')
            ->willThrowException(new \RuntimeException('DataHandler errors: Access denied'));

        $tool = new RecordMoveBatchTool($dataHandlerService);

        $this->expectException(\RuntimeException::class);
        $tool->execute('tt_content', '10,20', 5);
    }
}
