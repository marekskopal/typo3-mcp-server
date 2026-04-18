<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceAddTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FileReferenceAddTool::class)]
final class FileReferenceAddToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'image' => ['config' => ['type' => 'file']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['tx_test']);
    }

    public function testExecuteCreatesFileReferences(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createFileReferences')
            ->with('tx_test', 100, 'image', [42, 43])
            ->willReturn([201, 202]);

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('tx_test', 100, 'image', '42,43'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('tx_test', $result['table']);
        self::assertSame(100, $result['uid']);
        self::assertSame('image', $result['fieldName']);
        self::assertSame(2, $result['referencesCreated']);
        self::assertSame([201, 202], $result['referenceUids']);
    }

    public function testExecuteReturnsErrorForInvalidFieldName(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('tx_test', 100, 'bogus', '42'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('not a file field', $result['error']);
    }

    public function testExecuteReturnsErrorForEmptyFileUids(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $result = json_decode($tool->execute('tx_test', 100, 'image', '0,'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('No valid file UIDs provided', $result['error']);
    }

    public function testExecuteParsesMultipleFileUids(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createFileReferences')
            ->with('tx_test', 1, 'image', [10, 20, 30])
            ->willReturn([101, 102, 103]);

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService(), new NullLogger());
        $tool->execute('tx_test', 1, 'image', '10, 20, 30');
    }

    public function testExecuteThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('createFileReferences')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService(), new NullLogger());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute('tx_test', 100, 'image', '42');
    }
}
