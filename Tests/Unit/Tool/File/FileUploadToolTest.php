<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileUploadTool::class)]
final class FileUploadToolTest extends TestCase
{
    public function testExecuteUploadsFileWithBase64Content(): void
    {
        $expectedResult = [
            'uid' => 42,
            'name' => 'upload.txt',
            'identifier' => '/upload.txt',
            'size' => 13,
            'mimeType' => 'text/plain',
        ];

        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('uploadFile')
            ->with(1, '/', 'upload.txt', 'Hello, World!')
            ->willReturn($expectedResult);

        $tool = new FileUploadTool($fileService);
        $result = json_decode(
            $tool->execute('upload.txt', base64_encode('Hello, World!')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(42, $result['uid']);
        self::assertSame('upload.txt', $result['name']);
        self::assertSame(13, $result['size']);
    }

    public function testExecuteUploadsFileWithPlainContent(): void
    {
        $expectedResult = [
            'uid' => 43,
            'name' => 'page.html',
            'identifier' => '/page.html',
            'size' => 20,
            'mimeType' => 'text/html',
        ];

        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('uploadFile')
            ->with(1, '/', 'page.html', '<h1>Hello World</h1>')
            ->willReturn($expectedResult);

        $tool = new FileUploadTool($fileService);
        $result = json_decode(
            $tool->execute('page.html', content: '<h1>Hello World</h1>'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(43, $result['uid']);
        self::assertSame('page.html', $result['name']);
    }

    public function testExecutePassesDirectoryAndStorage(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('uploadFile')
            ->with(2, '/uploads/', 'file.pdf', 'abc')
            ->willReturn([
                'uid' => 50,
                'name' => 'file.pdf',
                'identifier' => '/uploads/file.pdf',
                'size' => 3,
                'mimeType' => 'application/pdf',
            ]);

        $tool = new FileUploadTool($fileService);
        $tool->execute('file.pdf', base64_encode('abc'), directoryPath: '/uploads/', storageUid: 2);
    }

    public function testExecuteThrowsWhenBothContentAndBase64Provided(): void
    {
        $fileService = $this->createStub(FileService::class);
        $tool = new FileUploadTool($fileService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Provide either "content" or "base64Content", not both');

        $tool->execute('test.txt', base64_encode('data'), 'plain text');
    }

    public function testExecuteThrowsWhenNeitherContentNorBase64Provided(): void
    {
        $fileService = $this->createStub(FileService::class);
        $tool = new FileUploadTool($fileService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Either "content" or "base64Content" must be provided');

        $tool->execute('test.txt');
    }

    public function testExecuteThrowsOnInvalidBase64(): void
    {
        $fileService = $this->createStub(FileService::class);
        $tool = new FileUploadTool($fileService);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid base64 content');

        $tool->execute('test.txt', '!!!invalid!!!');
    }

    public function testExecuteThrowsExceptionOnServiceError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('uploadFile')
            ->willThrowException(new \RuntimeException('Storage not found'));

        $tool = new FileUploadTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage not found');

        $tool->execute('test.txt', content: 'some content');
    }
}
