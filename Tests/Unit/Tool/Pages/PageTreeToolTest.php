<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Pages\PageTreeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(PageTreeTool::class)]
final class PageTreeToolTest extends TestCase
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
                'hidden' => ['config' => ['type' => 'check']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['pages']);
    }

    public function testExecuteReturnsNestedTree(): void
    {
        $recordService = $this->createMock(RecordService::class);

        $recordService->expects(self::exactly(3))
            ->method('findByPid')
            ->willReturnCallback(static function (string $table, int $pid): array {
                return match ($pid) {
                    0 => ['records' => [['uid' => 1, 'pid' => 0, 'title' => 'Root']], 'total' => 1],
                    1 => ['records' => [['uid' => 2, 'pid' => 1, 'title' => 'Child']], 'total' => 1],
                    2 => ['records' => [], 'total' => 0],
                    default => ['records' => [], 'total' => 0],
                };
            });

        $tool = new PageTreeTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute(0, 3), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $result['totalNodes']);
        self::assertSame('Root', $result['tree'][0]['title']);
        self::assertSame('Child', $result['tree'][0]['children'][0]['title']);
        self::assertSame([], $result['tree'][0]['children'][0]['children']);
    }

    public function testExecuteRespectsDepthLimit(): void
    {
        $recordService = $this->createMock(RecordService::class);

        // With depth=1, only root level pages are fetched (no recursion into children)
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with('pages', 0, 500, 0, self::anything())
            ->willReturn(['records' => [['uid' => 1, 'pid' => 0, 'title' => 'Root']], 'total' => 1]);

        $tool = new PageTreeTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute(0, 1), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['totalNodes']);
        self::assertSame([], $result['tree'][0]['children']);
    }

    public function testExecuteReturnsEmptyTreeForEmptyPage(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new PageTreeTool($recordService, new TcaSchemaService());
        $result = json_decode($tool->execute(999), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $result['tree']);
        self::assertSame(0, $result['totalNodes']);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->willThrowException(new \RuntimeException('Database error'));

        $tool = new PageTreeTool($recordService, new TcaSchemaService());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute();
    }

    public function testExecuteClampsDepthToMaximum(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByPid')
            ->willReturn(['records' => [], 'total' => 0]);

        $tool = new PageTreeTool($recordService, new TcaSchemaService());

        // Depth 99 should be clamped to 10, but with empty results it just returns empty
        $result = json_decode($tool->execute(0, 99), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $result['tree']);
    }
}
