<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileSearchTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileSearchTool::class)]
final class FileSearchToolTest extends TestCase
{
    public function testExecuteSearchesByNamePattern(): void
    {
        $expectedResult = [
            'files' => [['name' => 'logo.png', 'identifier' => '/logo.png', 'size' => 2048, 'mimeType' => 'image/png', 'extension' => 'png', 'storage' => 1]],
            'total' => 1,
        ];

        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('searchFiles')
            ->with(1, 'logo', '', 20, 0)
            ->willReturn($expectedResult);

        $tool = new FileSearchTool($fileService);
        $result = json_decode($tool->execute('logo'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('logo.png', $result['files'][0]['name']);
    }

    public function testExecuteSearchesByExtension(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('searchFiles')
            ->with(1, '', 'pdf', 20, 0)
            ->willReturn(['files' => [], 'total' => 0]);

        $tool = new FileSearchTool($fileService);
        $tool->execute('', 'pdf');
    }

    public function testExecuteSearchesByNamePatternAndExtension(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('searchFiles')
            ->with(2, 'report', 'pdf', 10, 5)
            ->willReturn(['files' => [], 'total' => 0]);

        $tool = new FileSearchTool($fileService);
        $tool->execute('report', 'pdf', 2, 10, 5);
    }

    public function testExecuteReturnsErrorWhenNoFilterProvided(): void
    {
        $fileService = $this->createStub(FileService::class);

        $tool = new FileSearchTool($fileService);
        $result = json_decode($tool->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('namePattern or extension', $result['error']);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('searchFiles')
            ->willThrowException(new \RuntimeException('Storage not found'));

        $tool = new FileSearchTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage not found');

        $tool->execute('logo');
    }
}
