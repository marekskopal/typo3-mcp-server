<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\DirectoryCreateTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(DirectoryCreateTool::class)]
final class DirectoryCreateToolTest extends TestCase
{
    public function testExecuteCreatesDirectoryAndReturnsJson(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('createDirectory')
            ->with(1, '/', 'newdir')
            ->willReturn(['name' => 'newdir', 'identifier' => '/newdir/']);

        $tool = new DirectoryCreateTool($fileService);
        $result = json_decode($tool->execute('newdir'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('newdir', $result['name']);
        self::assertSame('/newdir/', $result['identifier']);
    }

    public function testExecutePassesParentPathAndStorage(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('createDirectory')
            ->with(2, '/uploads/', 'images')
            ->willReturn(['name' => 'images', 'identifier' => '/uploads/images/']);

        $tool = new DirectoryCreateTool($fileService);
        $tool->execute('images', '/uploads/', 2);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('createDirectory')
            ->willThrowException(new \RuntimeException('Folder already exists'));

        $tool = new DirectoryCreateTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Folder already exists');

        $tool->execute('existing');
    }
}
