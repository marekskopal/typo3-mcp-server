<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryDeleteTool;
use MarekSkopal\MsMcpServer\Tool\Result\FileDeletedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DirectoryDeleteTool::class)]
final class DirectoryDeleteToolTest extends TestCase
{
    public function testExecuteDeletesDirectoryAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('deleteDirectory')
            ->with(1, '/old/', false);

        $tool = new DirectoryDeleteTool($fileService, new NullLogger());
        $result = $tool->execute('/old/');

        self::assertInstanceOf(FileDeletedResult::class, $result);
        self::assertSame('/old/', $result->identifier);
    }

    public function testExecutePassesRecursiveFlag(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('deleteDirectory')
            ->with(1, '/old/', true);

        $tool = new DirectoryDeleteTool($fileService, new NullLogger());
        $tool->execute('/old/', true);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('deleteDirectory')
            ->willThrowException(new \RuntimeException('Folder not empty'));

        $tool = new DirectoryDeleteTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Folder not empty');

        $tool->execute('/nonempty/');
    }
}
