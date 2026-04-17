<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileUploadTool::class)]
final class FileUploadToolTest extends TestCase
{
    public function testExecuteUploadsFileAndReturnsResult(): void
    {
        $expectedResult = [
            'uid' => 42,
            'name' => 'upload.txt',
            'identifier' => '/upload.txt',
            'size' => 13,
            'mimeType' => 'text/plain',
        ];

        $base64 = base64_encode('Hello, World!');

        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('uploadFile')
            ->with(1, '/', 'upload.txt', $base64)
            ->willReturn($expectedResult);

        $tool = new FileUploadTool($fileService, new NullLogger());
        $result = json_decode($tool->execute('upload.txt', $base64), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertSame('upload.txt', $result['name']);
        self::assertSame(13, $result['size']);
    }

    public function testExecutePassesDirectoryAndStorage(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('uploadFile')
            ->with(2, '/uploads/', 'file.pdf', 'YWJj')
            ->willReturn(['uid' => 50, 'name' => 'file.pdf', 'identifier' => '/uploads/file.pdf', 'size' => 3, 'mimeType' => 'application/pdf']);

        $tool = new FileUploadTool($fileService, new NullLogger());
        $tool->execute('file.pdf', 'YWJj', '/uploads/', 2);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('uploadFile')
            ->willThrowException(new \RuntimeException('Invalid base64 content'));

        $tool = new FileUploadTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid base64 content');

        $tool->execute('test.txt', '!!!invalid!!!');
    }
}
