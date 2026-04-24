<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileMoveTool;
use MarekSkopal\MsMcpServer\Tool\Result\FileMovedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FileMoveTool::class)]
final class FileMoveToolTest extends TestCase
{
    public function testExecuteMovesFileAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('moveFile')
            ->with(1, '/test.txt', '/target/');

        $tool = new FileMoveTool($fileService, new NullLogger());
        $result = $tool->execute('/test.txt', '/target/');

        self::assertInstanceOf(FileMovedResult::class, $result);
        self::assertSame('/test.txt', $result->identifier);
        self::assertSame('/target/', $result->targetDirectory);
        self::assertTrue($result->moved);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('moveFile')
            ->willThrowException(new \RuntimeException('File not found'));

        $tool = new FileMoveTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('File not found');

        $tool->execute('/nonexistent.txt', '/target/');
    }
}
