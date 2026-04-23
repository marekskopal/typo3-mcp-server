<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceRemoveTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceRemovedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FileReferenceRemoveTool::class)]
final class FileReferenceRemoveToolTest extends TestCase
{
    public function testExecuteRemovesSingleReference(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('sys_file_reference', 201);

        $tool = new FileReferenceRemoveTool($dataHandlerService, new NullLogger());
        $result = $tool->execute('201');

        self::assertInstanceOf(FileReferenceRemovedResult::class, $result);
        self::assertSame(1, $result->referencesRemoved);
        self::assertSame([201], $result->referenceUids);
    }

    public function testExecuteRemovesMultipleReferences(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::exactly(3))
            ->method('deleteRecord')
            ->willReturnCallback(function (string $table, int $uid): void {
                self::assertSame('sys_file_reference', $table);
                self::assertContains($uid, [201, 202, 203]);
            });

        $tool = new FileReferenceRemoveTool($dataHandlerService, new NullLogger());
        $result = $tool->execute('201, 202, 203');

        self::assertInstanceOf(FileReferenceRemovedResult::class, $result);
        self::assertSame(3, $result->referencesRemoved);
        self::assertSame([201, 202, 203], $result->referenceUids);
    }

    public function testExecuteReturnsErrorForEmptyUids(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('deleteRecord');

        $tool = new FileReferenceRemoveTool($dataHandlerService, new NullLogger());
        $result = $tool->execute('0,');

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('No valid reference UIDs provided', $result->error);
    }

    public function testExecuteFiltersInvalidUids(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('sys_file_reference', 42);

        $tool = new FileReferenceRemoveTool($dataHandlerService, new NullLogger());
        $result = $tool->execute('0, 42, -1');

        self::assertInstanceOf(FileReferenceRemovedResult::class, $result);
        self::assertSame(1, $result->referencesRemoved);
        self::assertSame([42], $result->referenceUids);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('deleteRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new FileReferenceRemoveTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute('201');
    }
}
