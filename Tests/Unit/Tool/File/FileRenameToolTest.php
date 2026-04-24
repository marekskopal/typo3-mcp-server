<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileRenameTool;
use MarekSkopal\MsMcpServer\Tool\Result\FileRenamedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileRenameTool::class)]
final class FileRenameToolTest extends TestCase
{
    public function testExecuteRenamesFileAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('renameFile')
            ->with(1, '/test.txt', 'new-name.txt');

        $tool = new FileRenameTool($fileService);
        $result = $tool->execute('/test.txt', 'new-name.txt');

        self::assertInstanceOf(FileRenamedResult::class, $result);
        self::assertSame('/test.txt', $result->identifier);
        self::assertSame('new-name.txt', $result->newName);
        self::assertTrue($result->renamed);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('renameFile')
            ->willThrowException(new \RuntimeException('File not found'));

        $tool = new FileRenameTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        $tool->execute('/nonexistent.txt', 'new-name.txt');
    }
}
