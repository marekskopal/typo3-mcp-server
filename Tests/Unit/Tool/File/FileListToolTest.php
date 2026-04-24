<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileListTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileListTool::class)]
final class FileListToolTest extends TestCase
{
    public function testExecuteReturnsFilesAndDirectories(): void
    {
        $expectedResult = [
            'files' => [['name' => 'test.txt', 'identifier' => '/test.txt', 'size' => 1024, 'mimeType' => 'text/plain', 'extension' => 'txt', 'modificationTime' => 1700000000]],
            'directories' => [['name' => 'subdir', 'identifier' => '/subdir/', 'modificationTime' => 1700000000]],
            'totalFiles' => 1,
            'totalDirectories' => 1,
        ];

        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('listDirectory')
            ->with(1, '/', 20, 0)
            ->willReturn($expectedResult);

        $tool = new FileListTool($fileService);
        $result = json_decode($tool->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['totalFiles']);
        self::assertSame('test.txt', $result['files'][0]['name']);
    }

    public function testExecutePassesParameters(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('listDirectory')
            ->with(2, '/uploads/', 10, 5)
            ->willReturn(['files' => [], 'directories' => [], 'totalFiles' => 0, 'totalDirectories' => 0]);

        $tool = new FileListTool($fileService);
        $tool->execute('/uploads/', 2, 10, 5);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('listDirectory')
            ->willThrowException(new \RuntimeException('Storage not found'));

        $tool = new FileListTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage not found');

        $tool->execute();
    }
}
