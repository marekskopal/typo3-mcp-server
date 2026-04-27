<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Scheduler;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use MarekSkopal\MsMcpServer\Tool\Scheduler\SchedulerToolRegistrar;
use Mcp\Exception\ToolCallException;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use const JSON_THROW_ON_ERROR;

#[CoversClass(SchedulerToolRegistrar::class)]
final class SchedulerToolRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')
            ->willReturnCallback(static fn(string $key): bool => $key === 'scheduler');
        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    public function testRegisterAddsToolsWhenExtensionLoaded(): void
    {
        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);
        $toolNames = array_column($tools, 'name');

        self::assertContains('scheduler_list', $toolNames);
        self::assertContains('scheduler_get', $toolNames);
        self::assertContains('scheduler_update', $toolNames);
        self::assertContains('scheduler_delete', $toolNames);
        self::assertCount(4, $tools);
    }

    public function testRegisterSkipsWhenExtensionNotLoaded(): void
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturn(false);
        ExtensionManagementUtility::setPackageManager($packageManager);

        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);
        self::assertSame([], $tools);
    }

    public function testListToolReturnsRecords(): void
    {
        $expectedResult = [
            'records' => [['uid' => 1, 'tasktype' => 'MyTask', 'disable' => 0]],
            'total' => 1,
        ];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'tx_scheduler_task',
                [],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'ASC',
            )
            ->willReturn($expectedResult);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $result = json_decode($closure(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('MyTask', $result['records'][0]['tasktype']);
    }

    public function testListToolWithTasktypeFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'tx_scheduler_task',
                ['tasktype' => ['operator' => 'like', 'value' => 'GarbageCollection']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(20, 0, 'GarbageCollection');
    }

    public function testListToolWithTaskGroupFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'tx_scheduler_task',
                ['task_group' => ['operator' => 'eq', 'value' => '5']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(20, 0, '', 5);
    }

    public function testListToolWithDisableFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'tx_scheduler_task',
                ['disable' => ['operator' => 'eq', 'value' => '1']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'ASC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(20, 0, '', -1, 1);
    }

    public function testListToolThrowsOnError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('search')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('DB error');

        $closure();
    }

    public function testGetToolReturnsRecord(): void
    {
        $record = ['uid' => 1, 'tasktype' => 'MyTask', 'disable' => 0, 'nextexecution' => 1700000000];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('tx_scheduler_task', 1, self::anything())
            ->willReturn($record);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');
        $result = json_decode($closure(1), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['uid']);
        self::assertSame('MyTask', $result['tasktype']);
    }

    public function testGetToolReturnsErrorWhenNotFound(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');
        $result = json_decode($closure(999), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Scheduler task not found', $result['error']);
    }

    public function testGetToolThrowsOnError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');

        $this->expectException(ToolCallException::class);
        $closure(1);
    }

    public function testUpdateToolCallsDataHandler(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with('tx_scheduler_task', 1, ['disable' => 1]);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'update');
        $result = $closure(1, json_encode(['disable' => 1], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordUpdatedResult::class, $result);
        self::assertSame(1, $result->uid);
        self::assertSame(['disable'], $result->updated);
    }

    public function testUpdateToolFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with('tx_scheduler_task', 1, ['description' => 'Updated']);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'update');
        $result = $closure(1, json_encode(['description' => 'Updated', 'tasktype' => 'ignored'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordUpdatedResult::class, $result);
        self::assertContains('tasktype', $result->ignoredFields);
    }

    public function testUpdateToolReturnsErrorWhenNoValidFields(): void
    {
        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            'update',
        );
        $result = $closure(1, json_encode(['tasktype' => 'invalid'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('No valid fields provided', $result->error);
    }

    public function testUpdateToolThrowsOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('updateRecord')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'update');

        $this->expectException(ToolCallException::class);
        $closure(1, json_encode(['disable' => 1], JSON_THROW_ON_ERROR));
    }

    public function testDeleteToolCallsDataHandler(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('tx_scheduler_task', 1);

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
    ): SchedulerToolRegistrar {
        return new SchedulerToolRegistrar(
            $recordService ?? $this->createStub(RecordService::class),
            $dataHandlerService ?? $this->createStub(DataHandlerService::class),
            new NullLogger(),
        );
    }

    private function getRegisteredClosure(
        RecordService $recordService,
        DataHandlerService $dataHandlerService,
        string $toolType,
    ): \Closure {
        $registrar = new SchedulerToolRegistrar($recordService, $dataHandlerService, new NullLogger());

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);

        $toolName = 'scheduler_' . $toolType;
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
