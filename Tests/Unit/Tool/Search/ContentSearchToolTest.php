<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Search\ContentSearchTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentSearchTool::class)]
final class ContentSearchToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => [
                'label' => 'header',
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l18n_parent',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'bodytext' => ['config' => ['type' => 'text']],
                'CType' => ['config' => ['type' => 'select']],
                'hidden' => ['config' => ['type' => 'check']],
                'sys_language_uid' => ['config' => ['type' => 'language']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']);
    }

    /**
     * A near-miss like `{"header":"Welcome"` (missing brace) used to fall through to a LIKE on the
     * raw literal, matching nothing — the client saw an empty result set and would conclude the
     * content elements do not exist, with no hint its query was malformed.
     */
    public function testExecuteReportsMalformedJsonInsteadOfLikeOnTheLiteral(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('search');

        $tool = new ContentSearchTool($recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('search must be a JSON object, but is not valid JSON');

        $tool->execute('{"header":"Welcome"');
    }

    public function testExecuteStillTreatsPlainTextAsAHeaderTerm(): void
    {
        // Only a value that opens with `{` or `[` is read as JSON, so a term that merely contains
        // a brace-free literal keeps working as a plain LIKE.
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with('tt_content', ['header' => ['operator' => 'like', 'value' => 'Welcome (draft)']])
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentSearchTool($recordService, new TcaSchemaService());
        $tool->execute('Welcome (draft)');
    }

    /** An unknown orderBy silently fell back to the default uid sort, so results looked misordered. */
    public function testExecuteReportsInvalidOrderByField(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('search');

        $tool = new ContentSearchTool($recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid orderBy field: nonexistent_field. Allowed fields: uid, pid, header');

        $tool->execute('Hello', 20, 0, -1, -1, 'nonexistent_field');
    }

    public function testExecuteWithPlainTextSearchesHeader(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'tt_content',
                ['header' => ['operator' => 'like', 'value' => 'Welcome']],
                20,
                0,
                self::anything(),
                null,
            )
            ->willReturn(['records' => [['uid' => 1, 'header' => 'Welcome']], 'total' => 1]);

        $tool = new ContentSearchTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('Welcome'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
    }

    public function testExecuteWithLanguageFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'tt_content',
                self::callback(static function (array $conditions): bool {
                    self::assertSame(['operator' => 'eq', 'value' => '1'], $conditions['sys_language_uid']);

                    return true;
                }),
                20,
                0,
                self::anything(),
                null,
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentSearchTool($recordService, new TcaSchemaService());
        $tool->execute('test', 20, 0, -1, 1);
    }

    public function testExecuteWithJsonConditions(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'tt_content',
                ['CType' => ['operator' => 'eq', 'value' => 'text']],
                20,
                0,
                self::anything(),
                null,
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new ContentSearchTool($recordService, new TcaSchemaService());
        $tool->execute('{"CType":{"op":"eq","value":"text"}}');
    }
}
