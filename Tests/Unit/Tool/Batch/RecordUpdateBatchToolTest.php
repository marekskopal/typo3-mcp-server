<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Batch;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Batch\RecordUpdateBatchTool;
use MarekSkopal\MsMcpServer\Tool\Result\BatchRecordsUpdatedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordUpdateBatchTool::class)]
final class RecordUpdateBatchToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => [
                'label' => 'title',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'hidden' => ['config' => ['type' => 'check']],
                'slug' => ['config' => ['type' => 'slug']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['pages']);
    }

    public function testExecuteUpdatesMultipleRecords(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1, 2, 3]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecords')
            ->with('pages', [1, 2, 3], ['hidden' => 1]);

        $tool = new RecordUpdateBatchTool($dataHandlerService, new TcaSchemaService(), $recordService);
        $result = $tool->execute('pages', '1,2,3', '{"hidden":1}');

        self::assertInstanceOf(BatchRecordsUpdatedResult::class, $result);
        self::assertSame([1, 2, 3], $result->uids);
        self::assertSame(3, $result->count);
        self::assertSame(['hidden'], $result->updatedFields);
        self::assertSame([], $result->ignoredFields);
        self::assertSame([], $result->skippedUids);
    }

    public function testExecuteReportsIgnoredFields(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecords')
            ->with('pages', [1], ['title' => 'New']);

        $tool = new RecordUpdateBatchTool($dataHandlerService, new TcaSchemaService(), $recordService);
        $result = $tool->execute('pages', '1', '{"title":"New","nonexistent":"value"}');

        self::assertSame(['title'], $result->updatedFields);
        self::assertSame(['nonexistent'], $result->ignoredFields);
    }

    public function testExecuteThrowsWhenNoValidFields(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1]);

        $dataHandlerService = $this->createStub(DataHandlerService::class);

        $tool = new RecordUpdateBatchTool($dataHandlerService, new TcaSchemaService(), $recordService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No valid writable fields');

        $tool->execute('pages', '1', '{"nonexistent":"value"}');
    }

    public function testExecuteSkipsNonExistentUids(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecords')
            ->with('pages', [1], ['hidden' => 1]);

        $tool = new RecordUpdateBatchTool($dataHandlerService, new TcaSchemaService(), $recordService);
        $result = $tool->execute('pages', '1,2,3', '{"hidden":1}');

        self::assertSame([1], $result->uids);
        self::assertSame(1, $result->count);
        self::assertSame([2, 3], $result->skippedUids);
    }

    public function testExecuteThrowsWhenNoUidsExist(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([]);

        $dataHandlerService = $this->createStub(DataHandlerService::class);

        $tool = new RecordUpdateBatchTool($dataHandlerService, new TcaSchemaService(), $recordService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('None of the provided UIDs exist');

        $tool->execute('pages', '1,2,3', '{"hidden":1}');
    }
}
