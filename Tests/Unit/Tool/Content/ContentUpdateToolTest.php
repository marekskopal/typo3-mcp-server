<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentUpdateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentUpdateTool::class)]
final class ContentUpdateToolTest extends TestCase
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
                'header_layout' => ['config' => ['type' => 'select']],
                'CType' => ['config' => ['type' => 'select']],
                'bodytext' => ['config' => ['type' => 'text']],
                'hidden' => ['config' => ['type' => 'check']],
                'colPos' => ['config' => ['type' => 'select']],
                'fe_group' => ['config' => ['type' => 'select']],
                'subheader' => ['config' => ['type' => 'input']],
                'list_type' => ['config' => ['type' => 'select']],
                'pi_flexform' => ['config' => ['type' => 'text']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tt_content']);
    }

    public function testExecuteUpdatesWithValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'tt_content',
                42,
                [
                    'header' => 'Updated Header',
                    'bodytext' => 'Updated Body',
                ],
            );

        $tool = new ContentUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $fields = json_encode(['header' => 'Updated Header', 'bodytext' => 'Updated Body'], JSON_THROW_ON_ERROR);
        $result = json_decode($tool->execute(42, $fields), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertSame(['header', 'bodytext'], $result['updated']);
    }

    public function testExecuteFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'tt_content',
                10,
                [
                    'header' => 'Valid',
                ],
            );

        $tool = new ContentUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $fields = json_encode(['header' => 'Valid', 'invalid_field' => 'Ignored', 'uid' => 999], JSON_THROW_ON_ERROR);
        $result = json_decode($tool->execute(10, $fields), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(10, $result['uid']);
        self::assertSame(['header'], $result['updated']);
    }

    public function testExecuteReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())
            ->method('updateRecord');

        $tool = new ContentUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $fields = json_encode(['invalid_field' => 'value', 'another_bad' => 'value'], JSON_THROW_ON_ERROR);
        $result = json_decode($tool->execute(10, $fields), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('No valid fields provided', $result['error']);
    }

    public function testExecuteUpdatesPluginFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(
                'tt_content',
                42,
                [
                    'list_type' => 'news_pi1',
                    'pi_flexform' => '<xml>config</xml>',
                ],
            );

        $tool = new ContentUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $fields = json_encode(
            ['list_type' => 'news_pi1', 'pi_flexform' => '<xml>config</xml>'],
            JSON_THROW_ON_ERROR,
        );
        $result = json_decode($tool->execute(42, $fields), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(42, $result['uid']);
        self::assertSame(['list_type', 'pi_flexform'], $result['updated']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentUpdateTool($dataHandlerService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $fields = json_encode(['header' => 'Test'], JSON_THROW_ON_ERROR);
        $tool->execute(1, $fields);
    }
}
