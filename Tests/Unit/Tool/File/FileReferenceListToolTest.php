<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceListTool;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceListResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FileReferenceListTool::class)]
final class FileReferenceListToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'image' => ['config' => ['type' => 'file']],
                'title' => ['config' => ['type' => 'input']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tx_test']);
    }

    public function testExecuteReturnsFileReferences(): void
    {
        $references = [
            ['uid' => 201, 'uid_local' => 10, 'title' => 'Logo', 'description' => '', 'alternative' => 'Alt', 'link' => '', 'crop' => '', 'autoplay' => 0, 'sorting_foreign' => 1],
            ['uid' => 202, 'uid_local' => 11, 'title' => '', 'description' => '', 'alternative' => '', 'link' => '', 'crop' => '', 'autoplay' => 0, 'sorting_foreign' => 2],
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findFileReferences')
            ->with('tx_test', 100, 'image')
            ->willReturn($references);

        $tool = new FileReferenceListTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = $tool->execute('tx_test', 100, 'image');

        self::assertInstanceOf(FileReferenceListResult::class, $result);
        self::assertSame('tx_test', $result->table);
        self::assertSame(100, $result->uid);
        self::assertSame('image', $result->fieldName);
        self::assertSame(2, $result->total);
        self::assertSame($references, $result->references);
    }

    public function testExecuteReturnsEmptyListWhenNoReferences(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findFileReferences')
            ->willReturn([]);

        $tool = new FileReferenceListTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = $tool->execute('tx_test', 100, 'image');

        self::assertInstanceOf(FileReferenceListResult::class, $result);
        self::assertSame(0, $result->total);
        self::assertSame([], $result->references);
    }

    public function testExecuteReturnsErrorForInvalidFieldName(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::never())->method('findFileReferences');

        $tool = new FileReferenceListTool($recordService, new TcaSchemaService(), new NullLogger());
        $result = $tool->execute('tx_test', 100, 'title');

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertStringContainsString('not a file field', $result->error);
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findFileReferences')
            ->willThrowException(new \RuntimeException('Database error'));

        $tool = new FileReferenceListTool($recordService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Database error');

        $tool->execute('tx_test', 100, 'image');
    }
}
