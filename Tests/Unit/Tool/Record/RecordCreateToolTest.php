<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Record;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Record\RecordCreateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordCreateTool::class)]
final class RecordCreateToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['sys_category'] = [
            'ctrl' => ['sortby' => 'sorting', 'languageField' => 'sys_language_uid', 'transOrigPointerField' => 'l10n_parent'],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'sys_language_uid' => ['config' => ['type' => 'language']],
                'l10n_parent' => ['config' => ['type' => 'select']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
    }

    public function testCreatesARecordInATableWithoutItsOwnCreateTool(): void
    {
        // #41: sys_category records (FAQ grouping) could be updated and deleted, but not created.
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findLastSortedUid')->willReturn(null);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_category', 5, ['title' => 'FAQ', 'sys_language_uid' => 0])
            ->willReturn(31);

        $tool = new RecordCreateTool($dataHandlerService, new TcaSchemaService(), $recordService);

        self::assertSame(
            ['uid' => 31, 'ignoredFields' => []],
            JsonResult::of($tool->execute('sys_category', 5, '{"title": "FAQ", "sys_language_uid": 3}')),
        );
    }

    public function testCreatesATranslationWithTheLanguageAndParentInOneCall(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_category', -31, ['title' => 'Otázky', 'l10n_parent' => 31, 'sys_language_uid' => 1])
            ->willReturn(32);

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 31, 'pid' => 5]);

        $tool = new RecordCreateTool($dataHandlerService, new TcaSchemaService(), $recordService);
        $tool->execute('sys_category', 5, '{"title": "Otázky", "l10n_parent": 31}', sysLanguageUid: 1, afterUid: 31);
    }

    public function testRejectsAnUnknownTable(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createRecord');

        $tool = new RecordCreateTool($dataHandlerService, new TcaSchemaService(), $this->createStub(RecordService::class));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown table "tx_nope"');

        $tool->execute('tx_nope', 5, '{"title": "x"}');
    }

    public function testRejectsFieldsWithNothingWritable(): void
    {
        $GLOBALS['TCA']['tx_plain'] = ['ctrl' => [], 'columns' => ['title' => ['config' => ['type' => 'input']]]];

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createRecord');

        $tool = new RecordCreateTool($dataHandlerService, new TcaSchemaService(), $this->createStub(RecordService::class));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No valid fields provided for table "tx_plain". Ignored, not writable: nope.');

        $tool->execute('tx_plain', 5, '{"nope": 1}');
    }
}
