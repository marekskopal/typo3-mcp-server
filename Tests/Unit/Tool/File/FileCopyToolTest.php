<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileCopyTool;
use MarekSkopal\MsMcpServer\Tool\Result\FileCopiedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileCopyTool::class)]
final class FileCopyToolTest extends TestCase
{
    public function testExecuteCopiesFileAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('copyFile')
            ->with(1, '/test.txt', '/target/');

        $tool = new FileCopyTool($fileService);
        $result = $tool->execute('/test.txt', '/target/');

        self::assertInstanceOf(FileCopiedResult::class, $result);
        self::assertSame('/test.txt', $result->identifier);
        self::assertSame('/target/', $result->targetDirectory);
        self::assertTrue($result->copied);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('copyFile')
            ->willThrowException(new \RuntimeException('File not found'));

        $tool = new FileCopyTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $tool->execute('/nonexistent.txt', '/target/');
    }
}
