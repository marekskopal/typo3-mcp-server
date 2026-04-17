<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Dynamic;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use Mcp\Exception\ToolCallException;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use const JSON_THROW_ON_ERROR;

#[CoversClass(DynamicToolRegistrar::class)]
final class DynamicToolRegistrarTest extends TestCase
{
    private const string TABLE = 'tx_test_domain_model_item';

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables'] = [
            self::TABLE => [
                'label' => 'Item',
                'prefix' => 'item',
                'listFields' => ['uid', 'pid', 'title'],
                'readFields' => ['uid', 'pid', 'title', 'description'],
                'writableFields' => ['title', 'description'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables']);
    }

    public function testRegisterAddsToolsToBuilder(): void
    {
        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        $this->addToAssertionCount(1);
    }

    public function testRegisterSkipsWhenNoTablesConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables'] = [];

        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        $this->addToAssertionCount(1);
    }

    public function testRegisterResolvesFieldsFromTcaWhenNotProvided(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables'] = [
            self::TABLE => [
                'label' => 'Item',
                'prefix' => 'item',
            ],
        ];

        $GLOBALS['TCA'][self::TABLE] = [
            'ctrl' => ['label' => 'title'],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'description' => ['config' => ['type' => 'text']],
            ],
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(self::TABLE, 0, 20, 0, ['uid', 'pid', 'title'])
            ->willReturn(['records' => [], 'total' => 0]);

        $registrar = new DynamicToolRegistrar(
            $recordService,
            $this->createMock(DataHandlerService::class),
            new TcaSchemaService(),
            new NullLogger(),
        );

        $builder = Server::builder();
        $registrar->register($builder);

        // Verify TCA resolution by invoking the list tool and checking correct fields were used
        $tools = $this->getRegisteredTools($builder);
        $listTool = null;
        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === 'item_list') {
                /** @var \Closure $listTool */
                $listTool = $tool['handler'];

                break;
            }
        }
        self::assertNotNull($listTool);
        $listTool();

        unset($GLOBALS['TCA'][self::TABLE]);
    }

    public function testRegisterSkipsTableWithNoReadFields(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables'] = [
            self::TABLE => [
                'label' => 'Item',
                'prefix' => 'item',
                'readFields' => [],
                'listFields' => [],
                'writableFields' => [],
            ],
        ];

        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);
        self::assertSame([], $tools);
    }

    public function testListToolCallsRecordService(): void
    {
        $expectedResult = ['records' => [['uid' => 1, 'title' => 'Test']], 'total' => 1];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByPid')
            ->with(self::TABLE, 10, 20, 0, ['uid', 'pid', 'title'])
            ->willReturn($expectedResult);

        $closure = $this->getRegisteredClosure($recordService, $this->createMock(DataHandlerService::class), 'list');
        $result = json_decode($closure(10), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('Test', $result['records'][0]['title']);
    }

    public function testListToolThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByPid')
            ->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($recordService, $this->createMock(DataHandlerService::class), 'list');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DB error');

        $closure(10);
    }

    public function testGetToolCallsRecordService(): void
    {
        $record = ['uid' => 1, 'pid' => 10, 'title' => 'Test', 'description' => 'Desc'];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with(self::TABLE, 1, ['uid', 'pid', 'title', 'description'])
            ->willReturn($record);

        $closure = $this->getRegisteredClosure($recordService, $this->createMock(DataHandlerService::class), 'get');
        $result = json_decode($closure(1), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['uid']);
        self::assertSame('Test', $result['title']);
    }

    public function testGetToolReturnsErrorWhenNotFound(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $closure = $this->getRegisteredClosure($recordService, $this->createMock(DataHandlerService::class), 'get');
        $result = json_decode($closure(999), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Item record not found', $result['error']);
    }

    public function testGetToolThrowsToolCallExceptionOnError(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->method('findByUid')
            ->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($recordService, $this->createMock(DataHandlerService::class), 'get');

        $this->expectException(ToolCallException::class);
        $closure(1);
    }

    public function testCreateToolCallsDataHandlerService(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(self::TABLE, 10, ['title' => 'New Item'])
            ->willReturn(42);

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'create',
        );
        $result = json_decode(
            $closure(10, json_encode(['title' => 'New Item'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(42, $result['uid']);
    }

    public function testCreateToolFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with(self::TABLE, 10, ['title' => 'Valid'])
            ->willReturn(42);

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'create',
        );
        $closure(10, json_encode(['title' => 'Valid', 'invalid_field' => 'ignored'], JSON_THROW_ON_ERROR));
    }

    public function testCreateToolReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('createRecord');

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'create',
        );
        $result = json_decode(
            $closure(10, json_encode(['invalid' => 'value'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('No valid fields provided', $result['error']);
    }

    public function testCreateToolThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->method('createRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'create',
        );

        $this->expectException(ToolCallException::class);
        $closure(10, json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR));
    }

    public function testUpdateToolCallsDataHandlerService(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with(self::TABLE, 1, ['title' => 'Updated']);

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'update',
        );
        $result = json_decode(
            $closure(1, json_encode(['title' => 'Updated'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $result['uid']);
        self::assertSame(['title'], $result['updated']);
    }

    public function testUpdateToolReturnsErrorWhenNoValidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::never())->method('updateRecord');

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'update',
        );
        $result = json_decode(
            $closure(1, json_encode(['invalid' => 'value'], JSON_THROW_ON_ERROR)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('No valid fields provided', $result['error']);
    }

    public function testUpdateToolThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->method('updateRecord')
            ->willThrowException(new \RuntimeException('DataHandler error'));

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'update',
        );

        $this->expectException(ToolCallException::class);
        $closure(1, json_encode(['title' => 'Test'], JSON_THROW_ON_ERROR));
    }

    public function testDeleteToolCallsDataHandlerService(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with(self::TABLE, 5);

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'delete',
        );
        $result = json_decode($closure(5), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5, $result['uid']);
        self::assertTrue($result['deleted']);
    }

    public function testDeleteToolThrowsToolCallExceptionOnError(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->method('deleteRecord')
            ->willThrowException(new \RuntimeException('Delete failed'));

        $closure = $this->getRegisteredClosure(
            $this->createMock(RecordService::class),
            $dataHandlerService,
            'delete',
        );

        $this->expectException(ToolCallException::class);
        $closure(5);
    }

    private function createRegistrar(
        ?RecordService $recordService = null,
        ?DataHandlerService $dataHandlerService = null,
    ): DynamicToolRegistrar {
        return new DynamicToolRegistrar(
            $recordService ?? $this->createMock(RecordService::class),
            $dataHandlerService ?? $this->createMock(DataHandlerService::class),
            new TcaSchemaService(),
            new NullLogger(),
        );
    }

    /**
     * Registers tools on a builder and extracts the closure for a specific tool type.
     * Uses reflection to access the builder's internal tools array.
     */
    private function getRegisteredClosure(
        RecordService $recordService,
        DataHandlerService $dataHandlerService,
        string $toolType,
    ): \Closure {
        $registrar = new DynamicToolRegistrar($recordService, $dataHandlerService, new TcaSchemaService(), new NullLogger());

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);

        $toolName = 'item_' . $toolType;
        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === $toolName) {
                /** @var \Closure $handler */
                $handler = $tool['handler'];

                return $handler;
            }
        }

        self::fail('Tool "' . $toolName . '" was not registered');
    }

    /**
     * @return list<array{handler: \Closure, name: string}>
     */
    private function getRegisteredTools(Server\Builder $builder): array
    {
        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('tools');
        /** @var list<array{handler: \Closure, name: string}> $tools */
        $tools = $property->getValue($builder);

        return $tools;
    }
}
