<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesUpdateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PagesUpdateTool::class)]
final class PagesUpdateToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['pages'] = [
            'ctrl' => [
                'label' => 'title',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'slug' => ['config' => ['type' => 'slug']],
                'doktype' => ['config' => ['type' => 'select']],
                'hidden' => ['config' => ['type' => 'check']],
                'nav_title' => ['config' => ['type' => 'input']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['pages']);
    }

    public function testExecuteUpdatesWithValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'pages',
                42,
                ['title' => 'New Title', 'slug' => '/new'],
            );

        $tool = new PagesUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(42, json_encode(['title' => 'New Title', 'slug' => '/new'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(42, $result['uid']);
        self::assertSame(['title', 'slug'], $result['updated']);
    }

    public function testExecuteFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'pages',
                10,
                ['title' => 'T'],
            );

        $tool = new PagesUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(10, json_encode(['title' => 'T', 'invalid_field' => 'x'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(10, $result['uid']);
        self::assertSame(['title'], $result['updated']);
        self::assertSame(['invalid_field'], $result['ignoredFields']);
    }

    public function testExecuteOmitsIgnoredFieldsWhenAllFieldsValid(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord');

        $tool = new PagesUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(10, json_encode(['title' => 'T'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayNotHasKey('ignoredFields', $result);
    }

    public function testExecuteReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())
            ->method('updateRecord');

        $tool = new PagesUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(1, json_encode(['bad_field' => 'x'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('No valid fields provided', $result['error']);
        self::assertSame(['bad_field'], $result['ignoredFields']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new PagesUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR));
    }
}
