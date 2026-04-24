<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesCreateTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PagesCreateTool::class)]
final class PagesCreateToolTest extends TestCase
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
                'subtitle' => ['config' => ['type' => 'input']],
                'abstract' => ['config' => ['type' => 'text']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['pages']);
    }

    public function testExecuteCreatesPageAndReturnsResult(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'pages',
                5,
                ['title' => 'New Page', 'doktype' => 1],
            )
            ->willReturn(123);

        $tool = new PagesCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(5, json_encode(['title' => 'New Page', 'doktype' => 1], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(123, $result->uid);
    }

    public function testExecuteCreatesPageWithAllFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'pages',
                1,
                [
                    'title' => 'Full Page',
                    'doktype' => 1,
                    'slug' => '/full-page',
                    'nav_title' => 'Nav Title',
                    'subtitle' => 'Subtitle',
                    'abstract' => 'Abstract text',
                ],
            )
            ->willReturn(789);

        $tool = new PagesCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(1, json_encode([
            'title' => 'Full Page',
            'doktype' => 1,
            'slug' => '/full-page',
            'nav_title' => 'Nav Title',
            'subtitle' => 'Subtitle',
            'abstract' => 'Abstract text',
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(789, $result->uid);
    }

    public function testExecuteFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'pages',
                0,
                ['title' => 'Page'],
            )
            ->willReturn(100);

        $tool = new PagesCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(0, json_encode(['title' => 'Page', 'invalid_field' => 'x'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(100, $result->uid);
        self::assertSame(['invalid_field'], $result->ignoredFields);
    }

    public function testExecuteOmitsIgnoredFieldsWhenAllFieldsValid(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willReturn(100);

        $tool = new PagesCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(0, json_encode(['title' => 'Page'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame([], $result->ignoredFields);
    }

    public function testExecuteReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())
            ->method('createRecord');

        $tool = new PagesCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(0, json_encode(['bad_field' => 'x'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('No valid fields provided', $result->error);
        self::assertSame(['bad_field'], $result->context['ignoredFields']);
    }

    public function testExecuteSetsSysLanguageUid(): void
    {
        $GLOBALS['TCA']['pages']['ctrl']['languageField'] = 'sys_language_uid';

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'pages',
                0,
                self::callback(static function (array $data): bool {
                    return $data['sys_language_uid'] === -1 && $data['title'] === 'Test';
                }),
            )
            ->willReturn(100);

        $tool = new PagesCreateTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(0, json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR), -1);

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(100, $result->uid);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new PagesCreateTool($dataHandlerService, new TcaSchemaService());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute(0, json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR));
    }
}
