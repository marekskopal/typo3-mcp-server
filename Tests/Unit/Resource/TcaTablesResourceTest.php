<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\TcaTablesResource;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(TcaTablesResource::class)]
final class TcaTablesResourceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
    }

    public function testExecuteReturnsTableList(): void
    {
        $GLOBALS['TCA'] = [
            'pages' => ['ctrl' => ['title' => 'Pages']],
            'tt_content' => ['ctrl' => ['title' => 'Content Elements']],
            'sys_file' => ['ctrl' => ['title' => 'Files']],
        ];

        $resource = new TcaTablesResource($this->permissionServiceAllowing());
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(3, $result);
        self::assertSame('pages', $result[0]['table']);
        self::assertSame('Pages', $result[0]['label']);
        self::assertSame('tt_content', $result[1]['table']);
        self::assertSame('Content Elements', $result[1]['label']);
        self::assertSame('sys_file', $result[2]['table']);
        self::assertSame('Files', $result[2]['label']);
    }

    public function testExecuteReturnsEmptyArrayWhenNoTca(): void
    {
        $GLOBALS['TCA'] = [];

        $resource = new TcaTablesResource($this->permissionServiceAllowing());
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $result);
    }

    public function testExecuteUsesTableNameWhenTitleMissing(): void
    {
        $GLOBALS['TCA'] = [
            'tx_custom' => ['ctrl' => []],
        ];

        $resource = new TcaTablesResource($this->permissionServiceAllowing());
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $result);
        self::assertSame('tx_custom', $result[0]['table']);
        self::assertSame('tx_custom', $result[0]['label']);
    }
    /**
     * The `table_schema` tool has always refused tables outside `tables_select`; this listing did
     * not, so a non-admin was handed a map of tables they cannot read.
     */
    public function testExecuteOmitsTablesTheUserMayNotSelect(): void
    {
        $GLOBALS['TCA'] = [
            'pages' => ['ctrl' => ['title' => 'Pages']],
            'tt_content' => ['ctrl' => ['title' => 'Content Elements']],
            'be_groups' => ['ctrl' => ['title' => 'Backend user groups']],
        ];

        $resource = new TcaTablesResource($this->permissionServiceAllowing(['pages', 'tt_content']));
        /** @var list<array{table: string}> $result */
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['pages', 'tt_content'], array_column($result, 'table'));
    }

    public function testExecuteReturnsEmptyListWhenNothingMaySelected(): void
    {
        $GLOBALS['TCA'] = ['pages' => ['ctrl' => ['title' => 'Pages']]];

        $resource = new TcaTablesResource($this->permissionServiceAllowing([]));

        self::assertSame([], json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * The listing is now filtered by `tables_select`; unless a case says otherwise the user may
     * read everything, which is also what an administrator gets.
     *
     * @param list<string>|null $allowedTables null allows every table
     */
    private function permissionServiceAllowing(?array $allowedTables = null): PermissionService
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturnCallback(
            static fn (string $table): bool => $allowedTables === null || in_array($table, $allowedTables, true),
        );

        return $permissionService;
    }
}
