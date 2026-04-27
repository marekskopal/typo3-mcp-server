<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Redirect;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Redirect\RedirectToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use Mcp\Exception\ToolCallException;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use const JSON_THROW_ON_ERROR;

#[CoversClass(RedirectToolRegistrar::class)]
final class RedirectToolRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')
            ->willReturnCallback(static fn(string $key): bool => $key === 'redirects');
        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    public function testRegisterAddsToolsWhenExtensionLoaded(): void
    {
        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);
        $toolNames = array_column($tools, 'name');

        self::assertContains('redirect_list', $toolNames);
        self::assertContains('redirect_get', $toolNames);
        self::assertContains('redirect_create', $toolNames);
        self::assertContains('redirect_update', $toolNames);
        self::assertContains('redirect_delete', $toolNames);
        self::assertCount(5, $tools);
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
        $expectedResult = ['records' => [['uid' => 1, 'source_host' => '*']], 'total' => 1];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_redirect',
                [],
                20,
                0,
                ['uid', 'pid', 'source_host', 'source_path', 'target', 'target_statuscode', 'disabled'],
                null,
                'uid',
                'DESC',
            )
            ->willReturn($expectedResult);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $result = json_decode($closure(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['total']);
        self::assertSame('*', $result['records'][0]['source_host']);
    }

    public function testListToolWithSourceHostFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_redirect',
                ['source_host' => ['operator' => 'like', 'value' => 'example.com']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'DESC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0, 20, 0, 'example.com');
    }

    public function testListToolWithSourcePathFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_redirect',
                ['source_path' => ['operator' => 'like', 'value' => '/old-path']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'DESC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0, 20, 0, '', '/old-path');
    }

    public function testListToolWithTargetFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_redirect',
                ['target' => ['operator' => 'like', 'value' => '/new-path']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'DESC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0, 20, 0, '', '', '/new-path');
    }

    public function testListToolWithDisabledFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_redirect',
                ['disabled' => ['operator' => 'eq', 'value' => '1']],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'DESC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0, 20, 0, '', '', '', 1);
    }

    public function testListToolWithPidFilter(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_redirect',
                [],
                20,
                0,
                self::anything(),
                42,
                'uid',
                'DESC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(42);
    }

    public function testListToolWithPidZeroPassesNull(): void
    {
        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->with(
                'sys_redirect',
                [],
                20,
                0,
                self::anything(),
                null,
                'uid',
                'DESC',
            )
            ->willReturn(['records' => [], 'total' => 0]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'list');
        $closure(0);
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
        $record = ['uid' => 1, 'pid' => 0, 'source_host' => '*', 'source_path' => '/old'];

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('findByUid')
            ->with('sys_redirect', 1, self::anything())
            ->willReturn($record);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');
        $result = json_decode($closure(1), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['uid']);
        self::assertSame('*', $result['source_host']);
    }

    public function testGetToolReturnsErrorWhenNotFound(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');
        $result = json_decode($closure(999), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Redirect record not found', $result['error']);
    }

    public function testGetToolThrowsOnError(): void
    {
        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), 'get');

        $this->expectException(ToolCallException::class);
        $closure(1);
    }

    public function testCreateToolWithRequiredParams(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_redirect', 0, [
                'source_host' => '*',
                'source_path' => '/old',
                'target' => '/new',
                'target_statuscode' => 301,
            ])
            ->willReturn(42);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure('*', '/old', '/new');

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(42, $result->uid);
    }

    public function testCreateToolWithCustomStatusCode(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_redirect', 10, self::callback(
                static fn(array $data): bool => $data['target_statuscode'] === 302,
            ))
            ->willReturn(43);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure('*', '/old', '/new', 10, 302);

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(43, $result->uid);
    }

    public function testCreateToolWithOptionalFieldsJson(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_redirect', 0, self::callback(
                static fn(array $data): bool => $data['source_host'] === '*'
                    && $data['source_path'] === '/old'
                    && $data['target'] === '/new'
                    && $data['target_statuscode'] === 301
                    && $data['force_https'] === 1
                    && $data['description'] === 'Test redirect',
            ))
            ->willReturn(44);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure(
            '*',
            '/old',
            '/new',
            0,
            301,
            json_encode(['force_https' => 1, 'description' => 'Test redirect'], JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertSame(44, $result->uid);
    }

    public function testCreateToolFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->willReturn(45);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        $result = $closure(
            '*',
            '/old',
            '/new',
            0,
            301,
            json_encode(['invalid_field' => 'ignored'], JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(RecordCreatedResult::class, $result);
        self::assertContains('invalid_field', $result->ignoredFields);
    }

    public function testCreateToolExplicitParamsTakePrecedenceOverFieldsJson(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('createRecord')
            ->with('sys_redirect', 0, self::callback(
                static fn(array $data): bool => $data['source_host'] === 'explicit.com'
                    && $data['target_statuscode'] === 302,
            ))
            ->willReturn(46);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');
        // fields JSON tries to override source_host and target_statuscode, but explicit params win
        $result = $closure(
            'explicit.com',
            '/old',
            '/new',
            0,
            302,
            json_encode(['source_host' => 'overridden.com', 'target_statuscode' => 307], JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(RecordCreatedResult::class, $result);
    }

    public function testCreateToolThrowsOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('createRecord')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'create');

        $this->expectException(ToolCallException::class);
        $closure('*', '/old', '/new');
    }

    public function testUpdateToolCallsDataHandler(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with('sys_redirect', 1, ['target' => '/updated']);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'update');
        $result = $closure(1, json_encode(['target' => '/updated'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordUpdatedResult::class, $result);
        self::assertSame(1, $result->uid);
        self::assertSame(['target'], $result->updated);
    }

    public function testUpdateToolFiltersInvalidFields(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with('sys_redirect', 1, ['target' => '/updated']);

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'update');
        $result = $closure(1, json_encode(['target' => '/updated', 'invalid' => 'ignored'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RecordUpdatedResult::class, $result);
        self::assertContains('invalid', $result->ignoredFields);
    }

    public function testUpdateToolReturnsErrorWhenNoValidFields(): void
    {
        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            'update',
        );
        $result = $closure(1, json_encode(['invalid' => 'value'], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('No valid fields provided', $result->error);
    }

    public function testUpdateToolThrowsOnError(): void
    {
        $dataHandlerService = $this->createStub(DataHandlerService::class);
        $dataHandlerService->method('updateRecord')->willThrowException(new \RuntimeException('DB error'));

        $closure = $this->getRegisteredClosure($this->createStub(RecordService::class), $dataHandlerService, 'update');

        $this->expectException(ToolCallException::class);
        $closure(1, json_encode(['target' => '/updated'], JSON_THROW_ON_ERROR));
    }

    public function testDeleteToolCallsDataHandler(): void
    {
        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('deleteRecord')
            ->with('sys_redirect', 1);

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
    ): RedirectToolRegistrar {
        return new RedirectToolRegistrar(
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
        $registrar = new RedirectToolRegistrar($recordService, $dataHandlerService, new NullLogger());

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);

        $toolName = 'redirect_' . $toolType;
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
