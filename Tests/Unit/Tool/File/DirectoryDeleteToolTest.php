<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryDeleteTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(DirectoryDeleteTool::class)]
final class DirectoryDeleteToolTest extends TestCase
{
    public function testExecuteDeletesDirectoryAndReturnsJson(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('deleteDirectory')
            ->with(1, '/old/', false);

        $tool = new DirectoryDeleteTool($fileService, new NullLogger());
        $result = json_decode($tool->execute('/old/'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('/old/', $result['identifier']);
        self::assertTrue($result['deleted']);
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
