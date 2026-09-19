<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\TypoScript;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolFactory;
use MarekSkopal\MsMcpServer\Tool\TypoScript\TypoScriptToolRegistrar;
use Mcp\Exception\ToolCallException;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(TypoScriptToolRegistrar::class)]
final class TypoScriptToolRegistrarTest extends TestCase
{
    private const array LIST_FIELDS = ['uid', 'pid', 'sorting', 'title', 'root', 'clear', 'hidden'];

    protected function setUp(): void
    {
        // The group is gated on the sys_template TCA rather than on an extension, because the table
        // ships in cms-frontend, a hard dependency.
        $GLOBALS['TCA']['sys_template'] = ['ctrl' => ['label' => 'title', 'adminOnly' => true]];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['sys_template']);
    }

    public function testRegisterAddsToolsWhenTableExists(): void
    {
        $builder = Server::builder();
        $this->createRegistrar()->register($builder);

        $toolNames = array_column($this->getRegisteredTools($builder), 'name');

        self::assertSame(
            ['typoscript_list', 'typoscript_get', 'typoscript_create', 'typoscript_update', 'typoscript_delete'],
            $toolNames,
        );
    }

    public function testRegisterSkipsWhenTableHasNoTca(): void
    {
        unset($GLOBALS['TCA']['sys_template']);

        $builder = Server::builder();
        $this->createRegistrar()->register($builder);

        self::assertSame([], $this->getRegisteredTools($builder));
    }

    public function testListToolReturnsRecords(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with('sys_template', [], 20, 0, self::LIST_FIELDS, null, 'uid', 'ASC')
            ->willReturn(['records' => [['uid' => 1, 'title' => 'Main']], 'total' => 1]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $result = JsonResult::of($closure());

        self::assertSame(1, $result['total']);
        self::assertSame('Main', $result['records'][0]['title']);
    }

    public function testListToolWithTitleFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_template',
                ['title' => ['operator' => 'like', 'value' => 'Main']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0, 20, 0, 'Main');
    }

    public function testListToolWithRootFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_template',
                ['root' => ['operator' => 'eq', 'value' => '1']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0, 20, 0, '', 1);
    }

    public function testListToolWithHiddenFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_template',
                ['hidden' => ['operator' => 'eq', 'value' => '0']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0, 20, 0, '', -1, 0);
    }

    public function testListToolWithPidFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with('sys_template', [], 20, 0, self::anything(), 42, 'uid', 'ASC')
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(42);
    }

    public function testListToolThrowsOnError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('search')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('An internal error occurred');

        $closure();
    }

    public function testGetToolReturnsRecord(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('sys_template', 1, self::anything())
            ->willReturn(['uid' => 1, 'title' => 'Main', 'config' => 'page = PAGE']);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');
        $result = JsonResult::of($closure(1));

        self::assertSame('page = PAGE', $result['config']);
    }

    public function testGetToolReportsNotFoundAsData(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');
        $result = JsonResult::of($closure(999));

        self::assertFalse($result['found']);
        self::assertSame('sys_template', $result['table']);
        self::assertSame('TypoScript template not found', $result['message']);
    }

    public function testCreateToolWithRequiredParams(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_template', 5, [
                'title' => 'Main',
                'root' => 1,
                'clear' => 3,
                'constants' => '',
                'config' => '',
            ])
            ->willReturn(42);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure(5, 'Main');

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(42, $result->uid);
    }

    public function testCreateToolWritesTypoScriptSource(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_template', 5, self::callback(
                static fn(array $data): bool => $data['constants'] === 'foo = bar'
                    && $data['config'] === 'page = PAGE'
                    && $data['root'] === 0
                    && $data['clear'] === 1,
            ))
            ->willReturn(43);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure(5, 'Sub', 0, 1, 'foo = bar', 'page = PAGE');

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(43, $result->uid);
    }

    public function testCreateToolAcceptsExtraFieldsJson(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_template', 5, self::callback(
                static fn(array $data): bool => $data['basedOn'] === '7,8'
                    && $data['include_static_file'] === 'EXT:fluid_styled_content/Configuration/TypoScript/',
            ))
            ->willReturn(44);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure(5, 'Sub', 1, 3, '', '', json_encode([
            'basedOn' => '7,8',
            'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
    }

    public function testCreateToolFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())->method('createRecord')->willReturn(45);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure(5, 'Sub', 1, 3, '', '', json_encode(['sitetitle' => 'removed in v11'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertContains('sitetitle', $result->ignoredFields);
    }

    public function testCreateToolExplicitParamsTakePrecedenceOverFieldsJson(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_template', 5, self::callback(
                static fn(array $data): bool => $data['title'] === 'Explicit' && $data['clear'] === 3,
            ))
            ->willReturn(46);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $closure(5, 'Explicit', 1, 3, '', '', json_encode(['title' => 'Overridden', 'clear' => 0], JSON_THROW_ON_ERROR));
    }

    public function testCreateToolThrowsOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('createRecord')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');

        $this->expectException(ToolCallException::class);
        $closure(5, 'Main');
    }

    public function testUpdateToolWritesConstants(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with('sys_template', 1, ['constants' => 'foo = bar']);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'update');
        $result = $closure(1, json_encode(['constants' => 'foo = bar'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordUpdatedResult::class, $result);
        self::assertSame(['constants'], $result->updated);
    }

    public function testUpdateToolRefusesWhenNoValidFields(): void
    {
        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            'update',
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No valid fields provided');

        $closure(1, json_encode(['nope' => 'value'], JSON_THROW_ON_ERROR));
    }

    public function testDeleteToolCallsDataHandler(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())->method('deleteRecord')->with('sys_template', 1);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'delete');
        $result = $closure(1);

        self::assertInstanceOf(RecordDeletedResult::class, $result);
        self::assertSame(1, $result->uid);
    }

    public function testDeleteToolThrowsOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('deleteRecord')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'delete');

        $this->expectException(ToolCallException::class);
        $closure(1);
    }

    private function createRegistrar(
        ?RecordService $recordService = null,
        ?DataHandlerService $dataHandlerService = null,
    ): TypoScriptToolRegistrar {
        $recordService ??= $this->createStub(RecordService::class);
        $dataHandlerService ??= $this->createStub(DataHandlerService::class);
        $logger = new NullLogger();
        $auditLogger = $this->createStub(AuditLogger::class);

        return new TypoScriptToolRegistrar(
            $recordService,
            $dataHandlerService,
            $logger,
            $auditLogger,
            new TableToolFactory($recordService, $dataHandlerService, $auditLogger, $logger),
        );
    }

    private function getRegisteredClosure(
        RecordService $recordService,
        DataHandlerService $dataHandlerService,
        string $toolType,
    ): \Closure {
        $builder = Server::builder();
        $this->createRegistrar($recordService, $dataHandlerService)->register($builder);

        $toolName = 'typoscript_' . $toolType;
        foreach ($this->getRegisteredTools($builder) as $tool) {
            if ($tool['name'] === $toolName) {
                return $tool['handler'];
            }
        }

        self::fail('Tool "' . $toolName . '" was not registered');
    }

    /**
     * The shared table tools are registered as `[$handler, '__invoke']` instance callables;
     * wrapping them in a Closure keeps the call sites reading as plain function calls.
     *
     * @return list<array{handler: \Closure, name: string}>
     */
    private function getRegisteredTools(Server\Builder $builder): array
    {
        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('tools');
        /** @var list<array{handler: callable, name: string}> $tools */
        $tools = $property->getValue($builder);

        return array_map(
            static fn(array $tool): array => [
                'handler' => \Closure::fromCallable($tool['handler']),
                'name' => $tool['name'],
            ],
            $tools,
        );
    }
}
