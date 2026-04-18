<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileUploadFromUrlTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileUploadFromUrlTool::class)]
final class FileUploadFromUrlToolTest extends TestCase
{
    public function testExecuteUploadsFileFromUrlAndReturnsResult(): void
    {
        $expectedResult = [
            'uid' => 42,
            'name' => 'image.png',
            'identifier' => '/image.png',
            'size' => 2048,
            'mimeType' => 'image/png',
        ];

        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('uploadFileFromUrl')
            ->with(1, '/', 'https://example.com/image.png', '')
            ->willReturn($expectedResult);

        $tool = new FileUploadFromUrlTool($fileService, new NullLogger());
        $result = json_decode($tool->execute('https://example.com/image.png'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertSame('image.png', $result['name']);
        self::assertSame(2048, $result['size']);
    }

    public function testExecutePassesDirectoryStorageAndFileName(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('uploadFileFromUrl')
            ->with(2, '/uploads/', 'https://example.com/file.pdf', 'custom.pdf')
            ->willReturn([
                'uid' => 50,
                'name' => 'custom.pdf',
                'identifier' => '/uploads/custom.pdf',
                'size' => 3072,
                'mimeType' => 'application/pdf',
            ]);

        $tool = new FileUploadFromUrlTool($fileService, new NullLogger());
        $tool->execute('https://example.com/file.pdf', '/uploads/', 2, 'custom.pdf');
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->method('uploadFileFromUrl')
            ->willThrowException(new \RuntimeException('Failed to download file from URL: https://example.com/missing.txt'));

        $tool = new FileUploadFromUrlTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Failed to download file from URL');

        $tool->execute('https://example.com/missing.txt');
    }
}
