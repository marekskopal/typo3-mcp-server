<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentCreateTool::class)]
final class ContentCreateToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => [
                'label' => 'header',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'CType' => ['config' => ['type' => 'select']],
                'bodytext' => ['config' => ['type' => 'text']],
                'hidden' => ['config' => ['type' => 'check']],
                'colPos' => ['config' => ['type' => 'select']],
                'list_type' => ['config' => ['type' => 'select']],
                'pi_flexform' => ['config' => ['type' => 'text']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']);
    }

    public function testExecuteCreatesContentAndReturnsJson(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tt_content',
                10,
                ['CType' => 'text', 'header' => 'My Header'],
            )
            ->willReturn(100);

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(10, json_encode(['CType' => 'text', 'header' => 'My Header'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(100, $result['uid']);
    }

    public function testExecuteCreatesContentWithAllParams(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tt_content',
                5,
                [
                    'CType' => 'html',
                    'header' => 'My Header',
                    'bodytext' => '<p>Body</p>',
                    'colPos' => 2,
                ],
            )
            ->willReturn(200);

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(5, json_encode([
                'CType' => 'html',
                'header' => 'My Header',
                'bodytext' => '<p>Body</p>',
                'colPos' => 2,
            ], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(200, $result['uid']);
    }

    public function testExecuteCreatesPluginContent(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tt_content',
                10,
                [
                    'CType' => 'list',
                    'header' => 'News Plugin',
                    'list_type' => 'news_pi1',
                    'pi_flexform' => '<xml>config</xml>',
                ],
            )
            ->willReturn(300);

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(10, json_encode([
                'CType' => 'list',
                'header' => 'News Plugin',
                'list_type' => 'news_pi1',
                'pi_flexform' => '<xml>config</xml>',
            ], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(300, $result['uid']);
    }

    public function testExecuteReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())
            ->method('createRecord');

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode(
            $tool->execute(10, json_encode(['bad_field' => 'x'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('No valid fields provided', $result['error']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, json_encode(['header' => 'Test'], JSON_THROW_ON_ERROR));
    }
}
