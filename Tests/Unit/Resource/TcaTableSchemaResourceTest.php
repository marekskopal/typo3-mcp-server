<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\TcaTableSchemaResource;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Exception\ResourceReadException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(TcaTableSchemaResource::class)]
final class TcaTableSchemaResourceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
    }

    public function testExecuteReturnsSchemaForTable(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => [
                    'label' => 'Title',
                    'config' => ['type' => 'input', 'required' => true, 'max' => 255],
                ],
            ],
        ];

        $resource = new TcaTableSchemaResource(new TcaSchemaService(), $this->permissionServiceAllowing());
        $result = json_decode($resource->execute('tx_test'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('tx_test', $result['table']);
        self::assertCount(1, $result['fields']);
        self::assertSame('title', $result['fields'][0]['name']);
        self::assertSame('input', $result['fields'][0]['type']);
    }

    public function testExecuteThrowsResourceReadExceptionWhenTableNotFound(): void
    {
        $resource = new TcaTableSchemaResource(new TcaSchemaService(), $this->permissionServiceAllowing());

        $this->expectException(ResourceReadException::class);
        $this->expectExceptionMessage('Table not found or has no readable fields: nonexistent_table');

        $resource->execute('nonexistent_table');
    }
    /**
     * The schema tool refuses a table outside `tables_select`; the resource returned the same
     * structure without asking, so it has to refuse too — and before it reveals whether the table
     * exists at all.
     */
    public function testExecuteThrowsWhenTableMayNotBeSelected(): void
    {
        $GLOBALS['TCA']['be_groups'] = [
            'ctrl' => [],
            'columns' => ['title' => ['config' => ['type' => 'input']]],
        ];

        $resource = new TcaTableSchemaResource(new TcaSchemaService(), $this->permissionServiceAllowing(false));

        $this->expectException(ResourceReadException::class);
        $this->expectExceptionMessage('Access denied: you do not have read permission for table: be_groups');

        $resource->execute('be_groups');
    }

    private function permissionServiceAllowing(bool $allowed = true): PermissionService
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn($allowed);

        return $permissionService;
    }
}
