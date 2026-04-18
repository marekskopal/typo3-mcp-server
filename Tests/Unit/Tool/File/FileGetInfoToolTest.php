<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileGetInfoTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileGetInfoTool::class)]
final class FileGetInfoToolTest extends TestCase
{
    public function testExecuteReturnsFileInfo(): void
    {
        $expectedResult = [
            'name' => 'image.png',
            'identifier' => '/images/image.png',
            'size' => 2048,
            'mimeType' => 'image/png',
            'extension' => 'png',
            'modificationTime' => 1700000000,
            'publicUrl' => '/fileadmin/images/image.png',
        ];

        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('getFileInfo')
            ->with(1, '/images/image.png')
            ->willReturn($expectedResult);

        $tool = new FileGetInfoTool($fileService, new NullLogger());
        $result = json_decode($tool->execute('/images/image.png'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('image.png', $result['name']);
        self::assertSame(2048, $result['size']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('getFileInfo')
            ->willThrowException(new \RuntimeException('File not found'));

        $tool = new FileGetInfoTool($fileService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('File not found');

        $tool->execute('/nonexistent.txt');
    }
}
