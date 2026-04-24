<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\TcaTablesResource;
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

        $resource = new TcaTablesResource();
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

        $resource = new TcaTablesResource();
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $result);
    }

    public function testExecuteUsesTableNameWhenTitleMissing(): void
    {
        $GLOBALS['TCA'] = [
            'tx_custom' => ['ctrl' => []],
        ];

        $resource = new TcaTablesResource();
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $result);
        self::assertSame('tx_custom', $result[0]['table']);
        self::assertSame('tx_custom', $result[0]['label']);
    }
}
