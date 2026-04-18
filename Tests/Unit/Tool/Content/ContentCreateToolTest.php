<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ContentCreateTool::class)]
final class ContentCreateToolTest extends TestCase
{
    public function testExecuteCreatesContentWithDefaults(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tt_content',
                10,
                [
                    'CType' => 'text',
                    'header' => '',
                    'bodytext' => '',
                    'colPos' => 0,
                    'hidden' => 0,
                    'sys_language_uid' => 0,
                ],
            )
            ->willReturn(100);

        $tool = new ContentCreateTool($dataHandlerService, new NullLogger());
        $result = json_decode($tool->execute(10), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(100, $result['uid']);
        self::assertSame('text', $result['CType']);
        self::assertSame('', $result['header']);
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
                    'hidden' => 1,
                    'sys_language_uid' => 1,
                ],
            )
            ->willReturn(200);

        $tool = new ContentCreateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(5, 'html', 'My Header', '<p>Body</p>', 2, true, 1),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(200, $result['uid']);
        self::assertSame('html', $result['CType']);
        self::assertSame('My Header', $result['header']);
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
                    'bodytext' => '',
                    'colPos' => 0,
                    'hidden' => 0,
                    'sys_language_uid' => 0,
                    'list_type' => 'news_pi1',
                    'pi_flexform' => '<xml>config</xml>',
                ],
            )
            ->willReturn(300);

        $tool = new ContentCreateTool($dataHandlerService, new NullLogger());
        $result = json_decode(
            $tool->execute(10, 'list', 'News Plugin', '', 0, false, 0, 'news_pi1', '<xml>config</xml>'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(300, $result['uid']);
        self::assertSame('list', $result['CType']);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentCreateTool($dataHandlerService, new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1);
    }
}
