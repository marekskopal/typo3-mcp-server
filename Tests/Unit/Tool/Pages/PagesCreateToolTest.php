<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Pages\PagesCreateTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PagesCreateTool::class)]
final class PagesCreateToolTest extends TestCase
{
    public function testExecuteCreatesPageAndReturnsJson(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'pages',
                5,
                [
                    'title' => 'New Page',
                    'doktype' => 1,
                    'hidden' => 0,
                ],
            )
            ->willReturn(123);

        $tool = new PagesCreateTool($dataHandlerService);
        $result = json_decode($tool->execute('New Page', 5), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(123, $result['uid']);
        self::assertSame('New Page', $result['title']);
    }

    public function testExecuteCreatesHiddenPage(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(
                'pages',
                0,
                [
                    'title' => 'Hidden Page',
                    'doktype' => 1,
                    'hidden' => 1,
                ],
            )
            ->willReturn(456);

        $tool = new PagesCreateTool($dataHandlerService);
        $result = json_decode($tool->execute('Hidden Page', 0, 1, true), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(456, $result['uid']);
    }

    public function testExecuteCreatesPageWithOptionalFields(): void
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
                    'hidden' => 0,
                    'slug' => '/full-page',
                    'nav_title' => 'Nav Title',
                    'subtitle' => 'Subtitle',
                    'abstract' => 'Abstract text',
                ],
            )
            ->willReturn(789);

        $tool = new PagesCreateTool($dataHandlerService);
        $result = json_decode(
            $tool->execute('Full Page', 1, 1, false, '/full-page', 'Nav Title', 'Subtitle', 'Abstract text'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(789, $result['uid']);
    }
}
