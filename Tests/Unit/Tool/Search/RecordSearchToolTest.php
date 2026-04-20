<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Search\RecordSearchTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(RecordSearchTool::class)]
final class RecordSearchToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => [
                'label' => 'title',
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
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

    public function testExecuteReturnsSearchResults(): void
    {
        $expectedResult = [
            'records' => [['uid' => 1, 'pid' => 0, 'title' => 'Hello World']],
            'total' => 1,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'pages',
                ['title' => 'Hello'],
                20,
                0,
                self::anything(),
                null,
            )
            ->willReturn($expectedResult);

        $tool = new RecordSearchTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('pages', '{"title":"Hello"}'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('Hello World', $result['records'][0]['title']);
    }

    public function testExecuteFiltersByPidWhenProvided(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'pages',
                ['title' => 'Test'],
                20,
                0,
                self::anything(),
                5,
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new RecordSearchTool($recordService, new TcaSchemaService(), new NullLogger());
        $tool->execute('pages', '{"title":"Test"}', 20, 0, 5);
    }

    public function testExecuteReportsIgnoredFields(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('search')->willReturn(['records' => [], 'total' => 0]);

        $tool = new RecordSearchTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute('pages', '{"title":"Test","nonexistent":"value"}'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(0, $result['total']);
        self::assertSame(['nonexistent'], $result['ignoredFields']);
    }

    public function testExecuteReturnsErrorForUnknownTable(): void
    {
        $recordService = $this->createStub(RecordService::class);

        $tool = new RecordSearchTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('unknown_table', '{"title":"Test"}'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('unknown_table', $result['error']);
    }

    public function testExecuteReturnsErrorForInvalidJson(): void
    {
        $recordService = $this->createStub(RecordService::class);

        $tool = new RecordSearchTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('pages', 'not-json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Invalid JSON', $result['error']);
    }

    public function testExecuteReturnsErrorWhenNoValidSearchFields(): void
    {
        $recordService = $this->createStub(RecordService::class);

        $tool = new RecordSearchTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute('pages', '{"nonexistent":"value"}'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('error', $result);
        self::assertSame(['nonexistent'], $result['ignoredFields']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('search')->willThrowException(new \RuntimeException('Database error'));

        $tool = new RecordSearchTool($recordService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute('pages', '{"title":"test"}');
    }
}
