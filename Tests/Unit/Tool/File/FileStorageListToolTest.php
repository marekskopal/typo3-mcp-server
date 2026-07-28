<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Tool\File\FileStorageListTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileStorageListTool::class)]
final class FileStorageListToolTest extends TestCase
{
    public function testExecuteReturnsStoragesWithMounts(): void
    {
        $fileService = $this->createMock(FileService::class);
        $fileService->expects(self::once())
            ->method('listStorages')
            ->willReturn([
                'storages' => [
                    [
                        'uid' => 1,
                        'name' => 'fileadmin',
                        'fullAccess' => false,
                        'mounts' => [['path' => '/user_upload/', 'title' => 'User upload', 'readOnly' => false]],
                    ],
                ],
            ]);

        $tool = new FileStorageListTool($fileService);
        $result = json_decode($tool->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($result['storages'][0]['fullAccess']);
        self::assertSame('/user_upload/', $result['storages'][0]['mounts'][0]['path']);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $fileService = $this->createStub(FileService::class);
        $fileService->method('listStorages')->willThrowException(new \RuntimeException('No backend user'));

        $tool = new FileStorageListTool($fileService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No backend user');

        $tool->execute();
    }
}
