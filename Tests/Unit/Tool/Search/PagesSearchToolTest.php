<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Search\PagesSearchTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PagesSearchTool::class)]
final class PagesSearchToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => ['label' => 'title', 'enablecolumns' => ['disabled' => 'hidden']],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'slug' => ['config' => ['type' => 'slug']],
                'doktype' => ['config' => ['type' => 'select']],
                'hidden' => ['config' => ['type' => 'check']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['pages']);
    }

    /**
     * A near-miss like `{"title":"Home"` (missing brace) used to fall through to a LIKE on the
     * raw literal, matching nothing — the client saw an empty result set and would conclude the
     * pages do not exist, with no hint its query was malformed.
     */
    public function testExecuteReportsMalformedJsonInsteadOfLikeOnTheLiteral(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('search');

        $tool = new PagesSearchTool($recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('search must be a JSON object, but is not valid JSON');

        $tool->execute('{"title":"Home"');
    }

    public function testExecuteStillTreatsPlainTextAsATitleTerm(): void
    {
        // Only a value that opens with `{` or `[` is read as JSON, so a term that merely contains
        // a brace-free literal keeps working as a plain LIKE.
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with('pages', ['title' => ['operator' => 'like', 'value' => 'Home (draft)']])
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new PagesSearchTool($recordService, new TcaSchemaService());
        $tool->execute('Home (draft)');
    }

    /** An unknown orderBy silently fell back to the default uid sort, so results looked misordered. */
    public function testExecuteReportsInvalidOrderByField(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('search');

        $tool = new PagesSearchTool($recordService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid orderBy field: nonexistent_field. Allowed fields: uid, pid, title');

        $tool->execute('Hello', 20, 0, -1, 'nonexistent_field');
    }

    public function testExecuteWithPlainTextSearchesTitle(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'pages',
                ['title' => ['operator' => 'like', 'value' => 'Hello']],
                20,
                0,
                self::anything(),
                null,
            )
            ->willReturn(['records' => [['uid' => 1, 'title' => 'Hello World']], 'total' => 1]);

        $tool = new PagesSearchTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('Hello'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
    }

    public function testExecuteWithJsonConditions(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'pages',
                ['doktype' => ['operator' => 'eq', 'value' => '1']],
                20,
                0,
                self::anything(),
                null,
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new PagesSearchTool($recordService, new TcaSchemaService());
        $tool->execute('{"doktype":{"op":"eq","value":"1"}}');
    }

    public function testExecuteWithPidFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with('pages', self::anything(), 20, 0, self::anything(), 5)
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new PagesSearchTool($recordService, new TcaSchemaService());
        $tool->execute('test', 20, 0, 5);
    }
}
