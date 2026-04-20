<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentListTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentListTool::class)]
final class ContentListToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => [
                'label' => 'header',
                'label_alt' => 'subheader',
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l18n_parent',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'subheader' => ['config' => ['type' => 'input']],
                'CType' => ['config' => ['type' => 'select']],
                'bodytext' => ['config' => ['type' => 'text']],
                'hidden' => ['config' => ['type' => 'check']],
                'colPos' => ['config' => ['type' => 'select']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']);
    }

    public function testExecuteCallsRecordServiceWithTcaDerivedFields(): void
    {
        $expectedResult = [
            'records' => [['uid' => 1, 'header' => 'Test Content']],
            'total' => 1,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                10,
                20,
                0,
                ['uid', 'pid', 'header', 'subheader', 'hidden', 'sys_language_uid', 'l18n_parent'],
            )
            ->willReturn($expectedResult);

        $tool = new ContentListTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute(10), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('Test Content', $result['records'][0]['header']);
    }

    public function testExecutePassesPaginationParameters(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                5,
                10,
                30,
                self::anything(),
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentListTool($recordService, new TcaSchemaService(), new NullLogger());
        $tool->execute(5, 10, 30);
    }

    public function testExecutePassesLanguageFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                10,
                20,
                0,
                self::anything(),
                0,
                'sys_language_uid',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentListTool($recordService, new TcaSchemaService(), new NullLogger());
        $tool->execute(10, 20, 0, 0);
    }

    public function testExecuteSkipsLanguageFilterWhenMinusOne(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                10,
                20,
                0,
                self::anything(),
                null,
                null,
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentListTool($recordService, new TcaSchemaService(), new NullLogger());
        $tool->execute(10, 20, 0, -1);
    }

    public function testExecuteUsesCustomSelectFields(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                10,
                20,
                0,
                self::callback(static function (array $fields): bool {
                    return in_array('uid', $fields, true)
                        && in_array('pid', $fields, true)
                        && in_array('header', $fields, true)
                        && in_array('bodytext', $fields, true);
                }),
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentListTool($recordService, new TcaSchemaService(), new NullLogger());
        $tool->execute(10, 20, 0, -1, 'header,bodytext');
    }

    public function testExecuteFallsBackToDefaultFieldsWhenAllSelectFieldsInvalid(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(
                'tt_content',
                10,
                20,
                0,
                ['uid', 'pid', 'header', 'subheader', 'hidden', 'sys_language_uid', 'l18n_parent'],
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentListTool($recordService, new TcaSchemaService(), new NullLogger());
        $tool->execute(10, 20, 0, -1, 'nonexistent_field,another_bad');
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->willThrowException(new \RuntimeException('Database error'));

        $tool = new ContentListTool($recordService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute(1);
    }
}
