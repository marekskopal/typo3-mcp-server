<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\BackendLayoutResource;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutCellResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutColumnResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutStructureResult;
use MarekSkopal\MsMcpServer\Service\BackendLayoutService;
use Mcp\Exception\ResourceReadException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use const JSON_THROW_ON_ERROR;

#[CoversClass(BackendLayoutResource::class)]
final class BackendLayoutResourceTest extends TestCase
{
    public function testExecuteReturnsBackendLayoutJson(): void
    {
        $result = new BackendLayoutResult(
            identifier: 'default',
            title: 'Default',
            description: '',
            columns: [
                new BackendLayoutColumnResult(colPos: 0, name: 'Main'),
            ],
            structure: new BackendLayoutStructureResult(
                colCount: 1,
                rowCount: 1,
                rows: [
                    [new BackendLayoutCellResult(colPos: 0, name: 'Main', colspan: 1, rowspan: 1)],
                ],
            ),
        );

        $service = $this->createStub(BackendLayoutService::class);
        $service->method('getBackendLayoutForPage')->willReturn($result);

        $resource = new BackendLayoutResource($service, new NullLogger());
        $json = json_decode($resource->execute('42'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('default', $json['identifier']);
        self::assertSame('Default', $json['title']);
        self::assertCount(1, $json['columns']);
        self::assertSame(0, $json['columns'][0]['colPos']);
        self::assertSame('Main', $json['columns'][0]['name']);
        self::assertSame(1, $json['structure']['colCount']);
        self::assertSame(1, $json['structure']['rowCount']);
        self::assertCount(1, $json['structure']['rows']);
    }

    public function testExecuteThrowsResourceReadExceptionOnError(): void
    {
        $service = $this->createStub(BackendLayoutService::class);
        $service->method('getBackendLayoutForPage')->willThrowException(new \RuntimeException('Page not found'));

        $resource = new BackendLayoutResource($service, new NullLogger());

        $this->expectException(ResourceReadException::class);
        $this->expectExceptionMessage('Page not found');

        $resource->execute('99');
    }
}
