<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Schema;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Schema\TableSchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(TableSchemaTool::class)]
final class TableSchemaToolTest extends TestCase
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
                'status' => [
                    'label' => 'Status',
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'items' => [
                            ['label' => 'Draft', 'value' => 0],
                            ['label' => 'Published', 'value' => 1],
                        ],
                    ],
                ],
            ],
        ];

        $tool = new TableSchemaTool(new TcaSchemaService(), $this->createPermissionService(true));
        $result = json_decode($tool->execute('tx_test'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('tx_test', $result['table']);
        self::assertCount(2, $result['fields']);
        self::assertSame('title', $result['fields'][0]['name']);
        self::assertSame('input', $result['fields'][0]['type']);
        self::assertTrue($result['fields'][0]['required']);
        self::assertSame('select', $result['fields'][1]['type']);
        self::assertCount(2, $result['fields'][1]['items']);
    }

    public function testExecuteReturnsErrorWhenTableNotFound(): void
    {
        $tool = new TableSchemaTool(new TcaSchemaService(), $this->createPermissionService(true));
        $result = json_decode($tool->execute('nonexistent_table'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('nonexistent_table', $result['error']);
    }

    public function testExecuteDeniesAccessWithoutSelectPermission(): void
    {
        $GLOBALS['TCA']['be_users'] = ['ctrl' => [], 'columns' => ['username' => ['config' => ['type' => 'input']]]];

        $tool = new TableSchemaTool(new TcaSchemaService(), $this->createPermissionService(false));
        $result = json_decode($tool->execute('be_users'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Access denied', $result['error']);
    }

    private function createPermissionService(bool $canSelect): PermissionService
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn($canSelect);

        return $permissionService;
    }
}
