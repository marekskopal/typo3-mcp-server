<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Content\ContentCreateTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    public function testExecuteCreatesContentAndReturnsResult(): void
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

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(10, json_encode(['CType' => 'text', 'header' => 'My Header'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(100, $result->uid);
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

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(5, json_encode([
            'CType' => 'html',
            'header' => 'My Header',
            'bodytext' => '<p>Body</p>',
            'colPos' => 2,
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(200, $result->uid);
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

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(10, json_encode([
            'CType' => 'list',
            'header' => 'News Plugin',
            'list_type' => 'news_pi1',
            'pi_flexform' => '<xml>config</xml>',
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(300, $result->uid);
    }

    public function testExecuteReturnsIgnoredFieldsWhenSomeFieldsDropped(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willReturn(100);

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(10, json_encode(['header' => 'Test', 'unknown_field' => 'x'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(100, $result->uid);
        self::assertSame(['unknown_field'], $result->ignoredFields);
    }

    public function testExecuteOmitsIgnoredFieldsWhenAllFieldsValid(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willReturn(100);

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(10, json_encode(['header' => 'Test'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(100, $result->uid);
        self::assertSame([], $result->ignoredFields);
    }

    public function testExecuteReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())
            ->method('createRecord');

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(10, json_encode(['bad_field' => 'x'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('No valid fields provided', $result->error);
        self::assertSame(['bad_field'], $result->context['ignoredFields']);
    }

    public function testExecuteSetsSysLanguageUid(): void
    {
        $GLOBALS['TCA']['tt_content']['ctrl']['languageField'] = 'sys_language_uid';

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tt_content',
                10,
                self::callback(static function (array $data): bool {
                    return $data['sys_language_uid'] === -1 && $data['header'] === 'Test';
                }),
            )
            ->willReturn(100);

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(10, json_encode(['header' => 'Test'], JSON_THROW_ON_ERROR), -1);

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(100, $result->uid);
    }

    public function testExecuteDefaultsSysLanguageUidToZero(): void
    {
        $GLOBALS['TCA']['tt_content']['ctrl']['languageField'] = 'sys_language_uid';

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'tt_content',
                10,
                self::callback(static function (array $data): bool {
                    return $data['sys_language_uid'] === 0;
                }),
            )
            ->willReturn(100);

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());
        $tool->execute(10, json_encode(['header' => 'Test'], JSON_THROW_ON_ERROR));
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new ContentCreateTool($dataHandlerService, new TcaSchemaService());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(1, json_encode(['header' => 'Test'], JSON_THROW_ON_ERROR));
    }
}
