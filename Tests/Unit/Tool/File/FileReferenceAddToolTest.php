<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\File\FileReferenceAddTool;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceAddedResult;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        unset($GLOBALS['TCA']['tx_test'], $GLOBALS['TCA']['sys_file_reference']);
    }

    public function testExecuteAppliesOneMetadataObjectToEveryReference(): void
    {
        // #39: alt text and title used to need one record_update_batch per reference afterwards.
        $this->setUpReferenceTca();

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createFileReferences')
            ->with('tx_test', 100, 'image', [42, 43], [
                0 => ['alternative' => 'Team photo'],
                1 => ['alternative' => 'Team photo'],
            ])
            ->willReturn([201, 202]);

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute('tx_test', 100, 'image', '42,43', '{"alternative": "Team photo", "uid_foreign": 5, "nope": 1}');

        self::assertSame(
            [
                'table' => 'tx_test',
                'uid' => 100,
                'fieldName' => 'image',
                'referencesCreated' => 2,
                'referenceUids' => [201, 202],
                'updated' => ['alternative'],
                'ignoredFields' => ['uid_foreign', 'nope'],
            ],
            JsonResult::of($result),
        );
    }

    public function testExecuteAppliesPerFileMetadataAndTakesTheFilesFromTheList(): void
    {
        $this->setUpReferenceTca();

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createFileReferences')
            ->with('tx_test', 100, 'image', [3, 2], [
                0 => ['alternative' => 'Court'],
                1 => ['alternative' => 'Hall', 'title' => 'Main hall'],
            ])
            ->willReturn([201, 202]);

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute(
            'tx_test',
            100,
            'image',
            '',
            '[{"uid": 3, "alternative": "Court"}, {"uid": "2", "alternative": "Hall", "title": "Main hall"}]',
        );

        self::assertSame(['alternative', 'title'], $result->updated);
    }

    public function testExecuteMatchesPerFileMetadataToFileUidsByUid(): void
    {
        $this->setUpReferenceTca();

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createFileReferences')
            ->with('tx_test', 100, 'image', [42, 43], [1 => ['title' => 'Second']])
            ->willReturn([201, 202]);

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());
        $tool->execute('tx_test', 100, 'image', '42,43', '[{"uid": 43, "title": "Second"}]');
    }

    public function testExecuteRejectsMetadataForAFileNotInFileUids(): void
    {
        $this->setUpReferenceTca();

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not in fileUids: 7');

        $tool->execute('tx_test', 100, 'image', '42', '[{"uid": 7, "title": "x"}]');
    }

    public function testExecuteRejectsMetadataWithNothingWritable(): void
    {
        $this->setUpReferenceTca();

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No valid fields provided for table "sys_file_reference"');

        $tool->execute('tx_test', 100, 'image', '42', '{"uid_local": 9}');
    }

    public function testExecuteRejectsMalformedMetadata(): void
    {
        $this->setUpReferenceTca();

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());

        foreach (['{"alternative": ', '"x"', '[{"alternative": "x"}]', '[1]'] as $metadata) {
            try {
                $tool->execute('tx_test', 100, 'image', '42', $metadata);
                self::fail('Expected a ToolCallException for ' . $metadata);
            } catch (ToolCallException $e) {
                self::assertStringContainsString('metadata', $e->getMessage());
            }
        }
    }

    private function setUpReferenceTca(): void
    {
        $GLOBALS['TCA']['sys_file_reference'] = [
            'ctrl' => ['languageField' => 'sys_language_uid', 'transOrigPointerField' => 'l10n_parent'],
            'columns' => [
                'uid_local' => ['config' => ['type' => 'group']],
                'uid_foreign' => ['config' => ['type' => 'input']],
                'sys_language_uid' => ['config' => ['type' => 'language']],
                'alternative' => ['config' => ['type' => 'text']],
                'title' => ['config' => ['type' => 'input']],
                'description' => ['config' => ['type' => 'text']],
            ],
        ];
    }

    public function testExecuteCreatesFileReferences(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createFileReferences')
            ->with('tx_test', 100, 'image', [42, 43])
            ->willReturn([201, 202]);

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());
        $result = $tool->execute('tx_test', 100, 'image', '42,43');

        self::assertInstanceOf(FileReferenceAddedResult::class, $result);
        self::assertSame('tx_test', $result->table);
        self::assertSame(100, $result->uid);
        self::assertSame('image', $result->fieldName);
        self::assertSame(2, $result->referencesCreated);
        self::assertSame([201, 202], $result->referenceUids);
    }

    public function testExecuteReturnsErrorForInvalidFieldName(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not a file field');

        $tool->execute('tx_test', 100, 'bogus', '42');
    }

    public function testExecuteReturnsErrorForEmptyFileUids(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createFileReferences');

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No valid file UIDs provided');

        $tool->execute('tx_test', 100, 'image', '0,');
    }

    public function testExecuteParsesMultipleFileUids(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createFileReferences')
            ->with('tx_test', 1, 'image', [10, 20, 30])
            ->willReturn([101, 102, 103]);

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());
        $tool->execute('tx_test', 1, 'image', '10, 20, 30');
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('createFileReferences')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $tool = new FileReferenceAddTool($dataHandlerService, new TcaSchemaService());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DataHandler error');

        $tool->execute('tx_test', 100, 'image', '42');
    }
}
