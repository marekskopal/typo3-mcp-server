<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Search\RecordCountTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(RecordCountTool::class)]
final class RecordCountToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => ['label' => 'title'],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'hidden' => ['config' => ['type' => 'check']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['pages']);
    }

    public function testExecuteReturnsCount(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('count')
            ->with('pages', null, [])
            ->willReturn(42);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('pages'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('pages', $result['table']);
        self::assertSame(42, $result['count']);
    }

    public function testExecuteFiltersByPid(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('count')
            ->with('pages', 5, [])
            ->willReturn(10);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('pages', 5), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(10, $result['count']);
    }

    public function testExecuteWithSearchConditions(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('count')
            ->with('pages', null, ['title' => ['operator' => 'like', 'value' => 'News']])
            ->willReturn(3);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('pages', -1, '{"title":"News"}'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(3, $result['count']);
    }

    public function testExecuteWithPidAndSearch(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('count')
            ->with('pages', 10, ['hidden' => ['operator' => 'eq', 'value' => '0']])
            ->willReturn(7);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('pages', 10, '{"hidden":{"op":"eq","value":"0"}}'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(7, $result['count']);
    }

    public function testExecuteReturnsErrorForUnknownTable(): void
    {
        $recordService = $this->createStub(RecordService::class);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('unknown_table'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('unknown_table', $result['error']);
    }

    public function testExecuteReturnsErrorForInvalidJson(): void
    {
        $recordService = $this->createStub(RecordService::class);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('pages', -1, 'not-json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid JSON', $result['error']);
    }

    public function testExecuteReportsIgnoredFields(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('count')->willReturn(0);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode(
            $tool->execute('pages', -1, '{"title":"Test","nonexistent":"value"}'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(0, $result['count']);
        self::assertSame(['nonexistent'], $result['ignoredFields']);
    }

    public function testExecuteWithEmptySearchReturnsTotal(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('count')
            ->with('pages', null, [])
            ->willReturn(100);

        $tool = new RecordCountTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute('pages', -1, ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(100, $result['count']);
    }
}
