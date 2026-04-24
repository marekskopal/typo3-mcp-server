<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryRenameTool;
use MarekSkopal\MsMcpServer\Tool\Result\DirectoryRenamedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DirectoryRenameTool::class)]
final class DirectoryRenameToolTest extends TestCase
{
    public function testExecuteRenamesDirectoryAndReturnsResult(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('renameDirectory')
            ->with(1, '/old-name/', 'new-name');

        $tool = new DirectoryRenameTool($fileService, new NullLogger());
        $result = $tool->execute('/old-name/', 'new-name');

        self::assertInstanceOf(DirectoryRenamedResult::class, $result);
        self::assertSame('/old-name/', $result->identifier);
        self::assertSame('new-name', $result->newName);
        self::assertTrue($result->renamed);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('renameDirectory')
            ->willThrowException(new \RuntimeException('Folder not found'));

        $tool = new DirectoryRenameTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Folder not found');

        $tool->execute('/nonexistent/', 'new-name');
    }
}
