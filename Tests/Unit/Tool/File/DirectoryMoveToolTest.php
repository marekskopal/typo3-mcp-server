<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryMoveTool;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryMovedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DirectoryMoveTool::class)]
final class DirectoryMoveToolTest extends TestCase
{
    public function testExecuteMovesDirectoryAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('moveDirectory')
            ->with(1, '/source/', '/target/');

        $tool = new DirectoryMoveTool($fileService, new NullLogger());
        $result = $tool->execute('/source/', '/target/');

        self::assertInstanceOf(DirectoryMovedResult::class, $result);
        self::assertSame('/source/', $result->identifier);
        self::assertSame('/target/', $result->targetDirectory);
        self::assertTrue($result->moved);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('moveDirectory')
            ->willThrowException(new \RuntimeException('Folder not found'));

        $tool = new DirectoryMoveTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Folder not found');

        $tool->execute('/nonexistent/', '/target/');
    }
}
