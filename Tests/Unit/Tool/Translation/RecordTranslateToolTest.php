<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Translation\RecordTranslateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

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
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => [
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
                'translationSource' => 'l10n_source',
            ],
            'columns' => [],
        ];

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('localizeRecord')
            ->with('pages', 1, 2)
            ->willReturn(99);

        $tool = new RecordTranslateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('pages', 1, 2), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(99, $result['uid']);
        self::assertSame('pages', $result['table']);
        self::assertSame(2, $result['targetLanguageId']);
    }

    public function testExecuteReturnsErrorForNonTranslatableTable(): void
    {
        $GLOBALS['TCA']['sys_file'] = [
            'ctrl' => [],
            'columns' => [],
        ];

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('localizeRecord');

        $tool = new RecordTranslateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('sys_file', 1, 1), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('not language-aware', $result['error']);
    }

    public function testExecuteThrowsToolCallExceptionOnDataHandlerError(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => [
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l18n_parent',
            ],
            'columns' => [],
        ];

        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('localizeRecord')
            ->willThrowException(new \RuntimeException('Translation already exists'));

        $tool = new RecordTranslateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Translation already exists');

        $tool->execute('tt_content', 42, 1);
    }
}
