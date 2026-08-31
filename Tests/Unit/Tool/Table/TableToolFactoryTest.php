<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Table;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\CreateHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\ListHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\TranslatableCreateHandler;
use MarekSkopal\MsMcpServer\Tool\Table\Handler\TranslatableListHandler;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolFactory;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(TableToolFactory::class)]
#[CoversClass(TableToolConfig::class)]
final class TableToolFactoryTest extends TestCase
{
    public function testConfigNamesToolsAndSubjects(): void
    {
        $config = $this->config();

        self::assertSame('thing_update', $config->toolName('update'));
        self::assertSame('Thing record', $config->subject());
        self::assertSame('Thing record', $config->subjectSentenceStart());
        self::assertSame('title, body', $config->writableFieldList());
        self::assertFalse($config->isTranslatable());
    }

    /** The scheduler's rows are tasks, and a lowercase label still capitalises for a message. */
    public function testConfigNounAndSentenceStart(): void
    {
        $config = $this->config(noun: 'task', label: 'scheduler');

        self::assertSame('scheduler task', $config->subject());
        self::assertSame('Scheduler task', $config->subjectSentenceStart());
    }

    public function testPicksTranslatableVariantsBySignature(): void
    {
        $plain = $this->factory()->list($this->config());
        $translatable = $this->factory()->list($this->config(languageField: 'sys_language_uid'));

        self::assertInstanceOf(ListHandler::class, $plain);
        self::assertInstanceOf(TranslatableListHandler::class, $translatable);

        // The variant exists because the signature *is* the tool's schema: only the translatable
        // one exposes sysLanguageUid.
        self::assertSame(
            ['pid', 'limit', 'offset', 'selectFields'],
            $this->parameterNames($plain),
        );
        self::assertSame(
            ['pid', 'limit', 'offset', 'sysLanguageUid', 'selectFields'],
            $this->parameterNames($translatable),
        );
    }

    public function testPicksTranslatableCreateVariant(): void
    {
        self::assertInstanceOf(CreateHandler::class, $this->factory()->create($this->config()));
        self::assertInstanceOf(
            TranslatableCreateHandler::class,
            $this->factory()->create($this->config(languageField: 'sys_language_uid')),
        );
    }

    /**
     * The point of the handler objects: a tool can be built and called on its own, with no MCP
     * builder and no registrar in the way.
     */
    public function testHandlerIsInvokableWithoutARegistrar(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with('tx_demo_thing', 7, ['title' => 'New']);

        $handler = $this->factory($dataHandlerService)->update($this->config());

        self::assertSame('thing_update', $handler->toolName());
        self::assertStringContainsString('Available fields: title, body.', $handler->description());

        $result = $handler(7, '{"title":"New","nope":1}');

        self::assertInstanceOf(RecordUpdatedResult::class, $result);
        self::assertSame(['nope'], $result->ignoredFields);
    }

    public function testUpdateReportsWhenNothingWritable(): void
    {
        $handler = $this->factory()->update($this->config());

        self::assertInstanceOf(ErrorResult::class, $handler(7, '{"nope":1}'));
    }

    /** Validation runs inside the audit wrapper, so a bad payload is still logged as a failure. */
    public function testMalformedFieldsIsAuditedAsAFailure(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('logFailure');
        $auditLogger->expects(self::never())->method('logSuccess');

        $handler = $this->factory(auditLogger: $auditLogger)->update($this->config());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('fields must be a JSON object');

        $handler(7, '{"title":');
    }

    /**
     * The shared batch handlers back the dynamic `*_delete_batch` tools, so the flag has to reach
     * them too — and nothing may be written when it is set.
     */
    public function testDeleteBatchDryRunPreviewsWithoutDeleting(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findExistingUids')->willReturn([1, 3]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('deleteRecords');

        $handler = $this->factory($dataHandlerService, recordService: $recordService)
            ->deleteBatch($this->config());
        $result = $handler('1,2,3', dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertSame([1, 3], $result->uids);
        self::assertSame([2], $result->skippedUids);
    }

    public function testDeleteDryRunReportsWithoutDeleting(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(['uid' => 4]);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('deleteRecord');

        $result = $this->factory($dataHandlerService, recordService: $recordService)
            ->delete($this->config())(4, dryRun: true);

        self::assertInstanceOf(RecordDeletedResult::class, $result);
        self::assertTrue($result->dryRun);
        self::assertFalse($result->deleted);
    }

    public function testDeleteDryRunReportsAnUnreachableRecord(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $result = $this->factory(recordService: $recordService)->delete($this->config())(999, dryRun: true);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('Thing record not found: 999', $result->error);
    }

    /** @return list<string> */
    private function parameterNames(object $handler): array
    {
        return array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod($handler, '__invoke'))->getParameters(),
        );
    }

    private function config(?string $languageField = null, string $noun = 'record', string $label = 'Thing'): TableToolConfig
    {
        return new TableToolConfig(
            tableName: 'tx_demo_thing',
            label: $label,
            prefix: 'thing',
            listFields: ['uid', 'pid', 'title'],
            readFields: ['uid', 'pid', 'title', 'body'],
            writableFields: ['title', 'body'],
            languageField: $languageField,
            noun: $noun,
        );
    }

    private function factory(
        ?DataHandlerService $dataHandlerService = null,
        ?AuditLogger $auditLogger = null,
        ?RecordService $recordService = null,
    ): TableToolFactory {
        return new TableToolFactory(
            $recordService ?? $this->createStub(RecordService::class),
            $dataHandlerService ?? $this->createStub(DataHandlerService::class),
            $auditLogger ?? $this->createStub(AuditLogger::class),
            new NullLogger(),
        );
    }
}
