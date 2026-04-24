<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileRenameTool;
use MarekSkopal\MsMcpServer\Tool\Result\FileRenamedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FileRenameTool::class)]
final class FileRenameToolTest extends TestCase
{
    public function testExecuteRenamesFileAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('renameFile')
            ->with(1, '/test.txt', 'new-name.txt');

        $tool = new FileRenameTool($fileService, new NullLogger());
        $result = $tool->execute('/test.txt', 'new-name.txt');

        self::assertInstanceOf(FileRenamedResult::class, $result);
        self::assertSame('/test.txt', $result->identifier);
        self::assertSame('new-name.txt', $result->newName);
        self::assertTrue($result->renamed);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('renameFile')
            ->willThrowException(new \RuntimeException('File not found'));

        $tool = new FileRenameTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('File not found');

        $tool->execute('/nonexistent.txt', 'new-name.txt');
    }
}
