<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Result\FileDeletedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileDeleteTool::class)]
final class FileDeleteToolTest extends TestCase
{
    public function testExecuteDeletesFileAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('deleteFile')
            ->with(1, '/test.txt');

        $tool = new FileDeleteTool($fileService);
        $result = $tool->execute('/test.txt');

        self::assertInstanceOf(FileDeletedResult::class, $result);
        self::assertSame('/test.txt', $result->identifier);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('deleteFile')
            ->willThrowException(new \RuntimeException('File not found'));

        $tool = new FileDeleteTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $tool->execute('/nonexistent.txt');
    }
}
