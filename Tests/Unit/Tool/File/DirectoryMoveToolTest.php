<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryMoveTool;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryMovedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DirectoryMoveTool::class)]
final class DirectoryMoveToolTest extends TestCase
{
    public function testExecuteMovesDirectoryAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('moveDirectory')
            ->with(1, '/source/', '/target/');

        $tool = new DirectoryMoveTool($fileService);
        $result = $tool->execute('/source/', '/target/');

        self::assertInstanceOf(DirectoryMovedResult::class, $result);
        self::assertSame('/source/', $result->identifier);
        self::assertSame('/target/', $result->targetDirectory);
        self::assertTrue($result->moved);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('moveDirectory')
            ->willThrowException(new \RuntimeException('Folder not found'));

        $tool = new DirectoryMoveTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Folder not found');

        $tool->execute('/nonexistent/', '/target/');
    }
}
