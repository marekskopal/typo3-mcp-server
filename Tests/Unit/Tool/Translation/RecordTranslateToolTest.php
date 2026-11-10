<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordTranslatedResult;
use MarekSkopal\MsMcpServer\Tool\Translation\RecordTranslateTool;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordTranslateTool::class)]
final class RecordTranslateToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
    }

    public function testExecuteReturnsNewUidOnSuccess(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 1, 'sys_language_uid' => 0]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('localizeRecord')
            ->with('pages', 1, 2, [])
            ->willReturn(99);

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());
        $result = $tool->execute('pages', 1, 2);

        self::assertInstanceOf(RecordTranslatedResult::class, $result);
        self::assertSame(99, $result->uid);
        self::assertSame('pages', $result->table);
        self::assertSame(2, $result->targetLanguageId);
    }

    public function testExecuteReturnsErrorForNonTranslatableTable(): void
    {
        $GLOBALS['TCA']['sys_file'] = [
            'ctrl' => [],
            'columns' => [],
        ];

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $recordService = $this->createStub(RecordService::class);

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not language-aware');

        $tool->execute('sys_file', 1, 1);
    }

    public function testExecuteReturnsErrorForRecordNotFound(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Record not found');

        $tool->execute('pages', 999, 1);
    }

    public function testExecuteReturnsErrorForAllLanguagesRecord(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 1, 'sys_language_uid' => -1]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('sys_language_uid = -1');

        $tool->execute('pages', 1, 1);
    }

    public function testExecuteReturnsErrorForAlreadyTranslatedRecord(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 5, 'sys_language_uid' => 2]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('already a translation');

        $tool->execute('pages', 5, 1);
    }

    public function testExecuteThrowsExceptionOnDataHandlerError(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => [
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l18n_parent',
            ],
            'columns' => [],
        ];

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 42, 'sys_language_uid' => 0]);

        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('localizeRecord')
            ->willThrowException(new \RuntimeException('Translation already exists'));

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Translation already exists');

        $tool->execute('tt_content', 42, 1);
    }

    public function testExecutePassesTheWritableTranslatedFieldsToTheLocalizeRun(): void
    {
        $this->setUpPagesTca();
        $GLOBALS['TCA']['pages']['columns'] = [
            'title' => ['config' => ['type' => 'input']],
            'slug' => ['config' => ['type' => 'slug']],
            'hidden' => ['config' => ['type' => 'check']],
            'sys_language_uid' => ['config' => ['type' => 'language']],
            'l10n_parent' => ['config' => ['type' => 'select']],
        ];

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 12, 'sys_language_uid' => 0]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('localizeRecord')
            ->with('pages', 12, 1, ['title' => 'Funkce', 'hidden' => 0])
            ->willReturn(40);

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());
        $result = $tool->execute(
            'pages',
            12,
            1,
            '{"title": "Funkce", "hidden": 0, "sys_language_uid": 2, "l10n_parent": 7, "pid": 1, "nope": 1}',
        );

        self::assertSame(
            [
                'uid' => 40,
                'table' => 'pages',
                'targetLanguageId' => 1,
                'updated' => ['title', 'hidden'],
                'ignoredFields' => ['sys_language_uid', 'l10n_parent', 'pid', 'nope'],
            ],
            JsonResult::of($result),
        );
    }

    public function testExecuteRejectsFieldsWithNothingWritable(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 12, 'sys_language_uid' => 0]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Ignored, not writable: nope');

        $tool->execute('pages', 12, 1, '{"nope": 1}');
    }

    public function testExecuteRejectsAFieldsValueThatIsNotAnObject(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 12, 'sys_language_uid' => 0]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);

        $tool->execute('pages', 12, 1, '"Funkce"');
    }

    private function setUpPagesTca(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => [
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
                'translationSource' => 'l10n_source',
            ],
            'columns' => [],
        ];
    }
}
