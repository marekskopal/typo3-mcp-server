<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordTranslatedResult;
use MarekSkopal\MsMcpServer\Tool\Translation\RecordTranslateTool;
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
            ->with('pages', 1, 2)
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
        $result = $tool->execute('sys_file', 1, 1);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('not language-aware', $result->error);
    }

    public function testExecuteReturnsErrorForRecordNotFound(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());
        $result = $tool->execute('pages', 999, 1);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('Record not found', $result->error);
    }

    public function testExecuteReturnsErrorForAllLanguagesRecord(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 1, 'sys_language_uid' => -1]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());
        $result = $tool->execute('pages', 1, 1);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('sys_language_uid = -1', $result->error);
    }

    public function testExecuteReturnsErrorForAlreadyTranslatedRecord(): void
    {
        $this->setUpPagesTca();

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 5, 'sys_language_uid' => 2]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, $recordService, new TcaSchemaService());
        $result = $tool->execute('pages', 5, 1);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('already a translation', $result->error);
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
