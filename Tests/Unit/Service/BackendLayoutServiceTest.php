<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutCellResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutColumnResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutStructureResult;
use MarekSkopal\MsMcpServer\Service\BackendLayoutService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\View\BackendLayout\BackendLayout;
use TYPO3\CMS\Backend\View\BackendLayoutView;

#[CoversClass(BackendLayoutService::class)]
final class BackendLayoutServiceTest extends TestCase
{
    public function testGetBackendLayoutForPageReturnsSingleColumnLayout(): void
    {
        $structure = [
            'usedColumns' => [0 => 'Main'],
            'colCount' => 1,
            'rowCount' => 1,
            '__colPosList' => ['0'],
            '__config' => [
                'backend_layout.' => [
                    'colCount' => '1',
                    'rowCount' => '1',
                    'rows.' => [
                        '1.' => [
                            'columns.' => [
                                '1.' => ['name' => 'Main', 'colPos' => '0', 'colspan' => '1', 'rowspan' => '1'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $backendLayout = new BackendLayout('default', 'Default', $structure);
        $backendLayout->setDescription('Default layout');

        $backendLayoutView = $this->createStub(BackendLayoutView::class);
        $backendLayoutView->method('getBackendLayoutForPage')->willReturn($backendLayout);

        $service = new BackendLayoutService($backendLayoutView);
        $result = $service->getBackendLayoutForPage(1);

        self::assertInstanceOf(BackendLayoutResult::class, $result);
        self::assertSame('default', $result->identifier);
        self::assertSame('Default', $result->title);
        self::assertSame('Default layout', $result->description);
        self::assertCount(1, $result->columns);
        self::assertSame(0, $result->columns[0]->colPos);
        self::assertSame('Main', $result->columns[0]->name);
        self::assertSame(1, $result->structure->colCount);
        self::assertSame(1, $result->structure->rowCount);
        self::assertCount(1, $result->structure->rows);
        self::assertCount(1, $result->structure->rows[0]);
        self::assertSame(0, $result->structure->rows[0][0]->colPos);
        self::assertSame('Main', $result->structure->rows[0][0]->name);
    }

    public function testGetBackendLayoutForPageReturnsMultiColumnLayout(): void
    {
        $structure = [
            'usedColumns' => [1 => 'Left', 0 => 'Main', 2 => 'Right'],
            'colCount' => 3,
            'rowCount' => 1,
            '__colPosList' => ['1', '0', '2'],
            '__config' => [
                'backend_layout.' => [
                    'colCount' => '3',
                    'rowCount' => '1',
                    'rows.' => [
                        '1.' => [
                            'columns.' => [
                                '1.' => ['name' => 'Left', 'colPos' => '1', 'colspan' => '1', 'rowspan' => '1'],
                                '2.' => ['name' => 'Main', 'colPos' => '0', 'colspan' => '1', 'rowspan' => '1'],
                                '3.' => ['name' => 'Right', 'colPos' => '2', 'colspan' => '1', 'rowspan' => '1'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $backendLayout = new BackendLayout('3-columns', '3 Columns', $structure);

        $backendLayoutView = $this->createStub(BackendLayoutView::class);
        $backendLayoutView->method('getBackendLayoutForPage')->willReturn($backendLayout);

        $service = new BackendLayoutService($backendLayoutView);
        $result = $service->getBackendLayoutForPage(5);

        self::assertCount(3, $result->columns);
        self::assertSame(1, $result->columns[0]->colPos);
        self::assertSame('Left', $result->columns[0]->name);
        self::assertSame(0, $result->columns[1]->colPos);
        self::assertSame('Main', $result->columns[1]->name);
        self::assertSame(2, $result->columns[2]->colPos);
        self::assertSame('Right', $result->columns[2]->name);

        self::assertSame(3, $result->structure->colCount);
        self::assertCount(1, $result->structure->rows);
        self::assertCount(3, $result->structure->rows[0]);
    }

    public function testGetBackendLayoutForPageHandlesColspanRowspan(): void
    {
        $structure = [
            'usedColumns' => [0 => 'Main', 1 => 'Sidebar', 3 => 'Footer'],
            'colCount' => 2,
            'rowCount' => 2,
            '__colPosList' => ['0', '1', '3'],
            '__config' => [
                'backend_layout.' => [
                    'colCount' => '2',
                    'rowCount' => '2',
                    'rows.' => [
                        '1.' => [
                            'columns.' => [
                                '1.' => ['name' => 'Main', 'colPos' => '0', 'colspan' => '1', 'rowspan' => '1'],
                                '2.' => ['name' => 'Sidebar', 'colPos' => '1', 'colspan' => '1', 'rowspan' => '1'],
                            ],
                        ],
                        '2.' => [
                            'columns.' => [
                                '1.' => ['name' => 'Footer', 'colPos' => '3', 'colspan' => '2', 'rowspan' => '1'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $backendLayout = new BackendLayout('with-footer', 'With Footer', $structure);

        $backendLayoutView = $this->createStub(BackendLayoutView::class);
        $backendLayoutView->method('getBackendLayoutForPage')->willReturn($backendLayout);

        $service = new BackendLayoutService($backendLayoutView);
        $result = $service->getBackendLayoutForPage(10);

        self::assertCount(2, $result->structure->rows);
        self::assertCount(2, $result->structure->rows[0]);
        self::assertCount(1, $result->structure->rows[1]);

        $footerCell = $result->structure->rows[1][0];
        self::assertSame(3, $footerCell->colPos);
        self::assertSame('Footer', $footerCell->name);
        self::assertSame(2, $footerCell->colspan);
        self::assertSame(1, $footerCell->rowspan);
    }

    public function testGetBackendLayoutForPageHandlesMissingColspanRowspan(): void
    {
        $structure = [
            'usedColumns' => [0 => 'Main'],
            'colCount' => 1,
            'rowCount' => 1,
            '__colPosList' => ['0'],
            '__config' => [
                'backend_layout.' => [
                    'colCount' => '1',
                    'rowCount' => '1',
                    'rows.' => [
                        '1.' => [
                            'columns.' => [
                                '1.' => ['name' => 'Main', 'colPos' => '0'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $backendLayout = new BackendLayout('simple', 'Simple', $structure);

        $backendLayoutView = $this->createStub(BackendLayoutView::class);
        $backendLayoutView->method('getBackendLayoutForPage')->willReturn($backendLayout);

        $service = new BackendLayoutService($backendLayoutView);
        $result = $service->getBackendLayoutForPage(1);

        self::assertSame(1, $result->structure->rows[0][0]->colspan);
        self::assertSame(1, $result->structure->rows[0][0]->rowspan);
    }
}
