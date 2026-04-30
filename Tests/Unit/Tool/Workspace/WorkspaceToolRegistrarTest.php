<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Workspace;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use MarekSkopal\MsMcpServer\Tool\Workspace\WorkspaceToolRegistrar;
use Mcp\Exception\ToolCallException;
use Mcp\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use const JSON_THROW_ON_ERROR;

#[CoversClass(WorkspaceToolRegistrar::class)]
final class WorkspaceToolRegistrarTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $tcaBackup = [];

    /** @var array<string, mixed> */
    private array $beUserBackup = [];

    protected function setUp(): void
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')
            ->willReturnCallback(static fn(string $key): bool => $key === 'workspaces');
        ExtensionManagementUtility::setPackageManager($packageManager);

        $this->tcaBackup = $GLOBALS['TCA'] ?? [];
        $this->beUserBackup = isset($GLOBALS['BE_USER']) ? ['BE_USER' => $GLOBALS['BE_USER']] : [];
        $GLOBALS['TCA'] = [];
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        if ($this->beUserBackup !== []) {
            $GLOBALS['BE_USER'] = $this->beUserBackup['BE_USER'];
        } else {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testRegisterAddsToolsWhenExtensionLoaded(): void
    {
        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);
        $toolNames = array_column($tools, 'name');

        self::assertContains('workspace_list', $toolNames);
        self::assertContains('workspace_get', $toolNames);
        self::assertContains('workspace_switch', $toolNames);
        self::assertContains('workspace_changes_list', $toolNames);
        self::assertContains('workspace_publish', $toolNames);
        self::assertContains('workspace_discard', $toolNames);
        self::assertContains('workspace_stage_set', $toolNames);
        self::assertCount(7, $tools);
    }

    public function testRegisterSkipsWhenExtensionNotLoaded(): void
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturn(false);
        ExtensionManagementUtility::setPackageManager($packageManager);

        $registrar = $this->createRegistrar();

        $builder = Server::builder();
        $registrar->register($builder);

        self::assertSame([], $this->getRegisteredTools($builder));
    }

    public function testListToolIncludesLiveAndAccessibleWorkspaces(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('checkWorkspace')
            ->willReturnCallback(static fn(int $uid): array|false => match ($uid) {
                0 => ['uid' => 0, '_ACCESS' => 'online'],
                1 => ['uid' => 1, '_ACCESS' => 'admin'],
                2 => ['uid' => 2, '_ACCESS' => 'member'],
                default => false,
            });
        $GLOBALS['BE_USER'] = $beUser;

        $recordService = $this->createMock(RecordService::class);
        $recordService->expects(self::once())
            ->method('search')
            ->willReturn([
                'records' => [
                    ['uid' => 1, 'title' => 'Marketing'],
                    ['uid' => 2, 'title' => 'Editorial'],
                    ['uid' => 99, 'title' => 'No Access'],
                ],
                'total' => 3,
            ]);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), null, 'workspace_list');
        /** @var list<array{uid: int, title: string, access: string}> $result */
        $result = json_decode($closure(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $result[0]['uid']);
        self::assertSame('Live workspace', $result[0]['title']);
        self::assertSame(1, $result[1]['uid']);
        self::assertSame('admin', $result[1]['access']);
        self::assertSame(2, $result[2]['uid']);
        self::assertSame('member', $result[2]['access']);
        self::assertCount(3, $result, 'Workspaces 99 should be filtered out as inaccessible');
    }

    public function testListToolThrowsWhenBackendUserMissing(): void
    {
        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            null,
            'workspace_list',
        );

        $this->expectException(ToolCallException::class);
        $closure();
    }

    public function testGetToolReturnsLiveWorkspace(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('checkWorkspace')->willReturn(['uid' => 0, '_ACCESS' => 'online']);
        $GLOBALS['BE_USER'] = $beUser;

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            null,
            'workspace_get',
        );
        /** @var array{uid: int, title: string, access: string} $result */
        $result = json_decode($closure(0), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $result['uid']);
        self::assertSame('Live workspace', $result['title']);
    }

    public function testGetToolReturnsErrorWhenAccessDenied(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('checkWorkspace')->willReturn(false);
        $GLOBALS['BE_USER'] = $beUser;

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            null,
            'workspace_get',
        );
        /** @var array{error: string} $result */
        $result = json_decode($closure(99), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Workspace not accessible to current user', $result['error']);
    }

    public function testGetToolReturnsErrorWhenWorkspaceNotFound(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('checkWorkspace')->willReturn(['uid' => 5, '_ACCESS' => 'admin']);
        $GLOBALS['BE_USER'] = $beUser;

        $recordService = $this->createStub(RecordService::class);
        $recordService->method('findByUid')->willReturn(null);

        $closure = $this->getRegisteredClosure($recordService, $this->createStub(DataHandlerService::class), null, 'workspace_get');
        /** @var array{error: string} $result */
        $result = json_decode($closure(5), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Workspace not found', $result['error']);
    }

    public function testSwitchToolPersistsWorkspace(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->method('checkWorkspace')->willReturn(['uid' => 5, '_ACCESS' => 'admin']);
        $beUser->expects(self::once())->method('setWorkspace')->with(5);
        $beUser->user = ['uid' => 1];
        $GLOBALS['BE_USER'] = $beUser;

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            null,
            'workspace_switch',
        );
        $result = $closure(5);

        self::assertInstanceOf(RecordUpdatedResult::class, $result);
        self::assertSame(1, $result->uid);
        self::assertSame(['workspace_id'], $result->updated);
    }

    public function testSwitchToolReturnsErrorWhenAccessDenied(): void
    {
        $beUser = $this->createMock(BackendUserAuthentication::class);
        $beUser->method('checkWorkspace')->willReturn(false);
        $beUser->expects(self::never())->method('setWorkspace');
        $GLOBALS['BE_USER'] = $beUser;

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            null,
            'workspace_switch',
        );
        $result = $closure(99);

        self::assertInstanceOf(ErrorResult::class, $result);
        self::assertSame('Workspace not accessible to current user', $result->error);
    }

    public function testChangesListInLiveReturnsEmpty(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->workspace = 0;
        $GLOBALS['BE_USER'] = $beUser;

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            $this->createStub(ConnectionPool::class),
            'workspace_changes_list',
        );
        /** @var array{workspaceId: int, tables: array<string, mixed>} $result */
        $result = json_decode($closure(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $result['workspaceId']);
        self::assertSame([], $result['tables']);
    }

    public function testChangesListReturnsWorkspaceVersionsGroupedByTable(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->workspace = 5;
        $GLOBALS['BE_USER'] = $beUser;

        $GLOBALS['TCA'] = [
            'pages' => ['ctrl' => ['versioningWS' => true]],
            'tt_content' => ['ctrl' => ['versioningWS' => true]],
            'tx_other' => ['ctrl' => []],
        ];

        $pagesResult = $this->createStub(Result::class);
        $pagesResult->method('fetchAllAssociative')->willReturn([
            ['uid' => 100, 'pid' => 1, 't3ver_oid' => 42, 't3ver_state' => 0, 't3ver_stage' => -10, 't3ver_wsid' => 5],
        ]);

        $contentResult = $this->createStub(Result::class);
        $contentResult->method('fetchAllAssociative')->willReturn([]);

        $pagesQb = $this->createWorkspaceQueryBuilder($pagesResult);
        $contentQb = $this->createWorkspaceQueryBuilder($contentResult);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(static fn(string $table): QueryBuilder => match ($table) {
                'pages' => $pagesQb,
                'tt_content' => $contentQb,
                default => throw new \RuntimeException('Unexpected table: ' . $table),
            });

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            $connectionPool,
            'workspace_changes_list',
        );
        /** @var array{workspaceId: int, tables: array<string, list<array<string, mixed>>>} $result */
        $result = json_decode($closure(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5, $result['workspaceId']);
        self::assertArrayHasKey('pages', $result['tables']);
        self::assertArrayNotHasKey('tt_content', $result['tables']);
        self::assertSame(100, $result['tables']['pages'][0]['uid']);
        self::assertSame(42, $result['tables']['pages'][0]['liveUid']);
        self::assertSame('default', $result['tables']['pages'][0]['state']);
        self::assertSame(-10, $result['tables']['pages'][0]['stage']);
    }

    public function testPublishToolBuildsSwapCommand(): void
    {
        $row = ['uid' => 100, 'pid' => 1, 't3ver_oid' => 42, 't3ver_state' => 0, 't3ver_stage' => 0, 't3ver_wsid' => 5];

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createWorkspaceQueryBuilder($result);
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('processCommand')
            ->with([
                'pages' => [
                    42 => [
                        'version' => ['action' => 'swap', 'swapWith' => 100],
                    ],
                ],
            ]);

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $dataHandlerService,
            $connectionPool,
            'workspace_publish',
        );
        $publishResult = $closure('pages', 100);

        self::assertInstanceOf(RecordUpdatedResult::class, $publishResult);
        self::assertSame(42, $publishResult->uid);
    }

    public function testPublishToolUsesWorkspaceUidForNewPlaceholders(): void
    {
        $row = ['uid' => 100, 'pid' => 1, 't3ver_oid' => 0, 't3ver_state' => 1, 't3ver_stage' => 0, 't3ver_wsid' => 5];

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createWorkspaceQueryBuilder($result);
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('processCommand')
            ->with([
                'pages' => [
                    100 => [
                        'version' => ['action' => 'swap', 'swapWith' => 100],
                    ],
                ],
            ]);

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $dataHandlerService,
            $connectionPool,
            'workspace_publish',
        );
        $closure('pages', 100);
    }

    public function testPublishToolReturnsErrorWhenVersionNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createWorkspaceQueryBuilder($result);
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            $connectionPool,
            'workspace_publish',
        );
        $publishResult = $closure('pages', 999);

        self::assertInstanceOf(ErrorResult::class, $publishResult);
        self::assertSame('Workspace version not found', $publishResult->error);
    }

    public function testDiscardToolBuildsClearWsidCommand(): void
    {
        $row = ['uid' => 100, 'pid' => 1, 't3ver_oid' => 42, 't3ver_state' => 0, 't3ver_stage' => 0, 't3ver_wsid' => 5];

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createWorkspaceQueryBuilder($result);
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('processCommand')
            ->with([
                'pages' => [
                    100 => ['version' => ['action' => 'clearWSID']],
                ],
            ]);

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $dataHandlerService,
            $connectionPool,
            'workspace_discard',
        );
        $discardResult = $closure('pages', 100);

        self::assertInstanceOf(RecordDeletedResult::class, $discardResult);
        self::assertSame(100, $discardResult->uid);
    }

    public function testStageSetToolUpdatesT3verStage(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('workspaceCheckStageForCurrent')->willReturn(true);
        $GLOBALS['BE_USER'] = $beUser;

        $row = ['uid' => 100, 'pid' => 1, 't3ver_oid' => 42, 't3ver_state' => 0, 't3ver_stage' => 0, 't3ver_wsid' => 5];

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($row);

        $queryBuilder = $this->createWorkspaceQueryBuilder($result);
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $dataHandlerService = $this->createMock(DataHandlerService::class);
        $dataHandlerService->expects(self::once())
            ->method('updateRecord')
            ->with('pages', 100, ['t3ver_stage' => -10]);

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $dataHandlerService,
            $connectionPool,
            'workspace_stage_set',
        );
        $stageResult = $closure('pages', 100, -10);

        self::assertInstanceOf(RecordUpdatedResult::class, $stageResult);
        self::assertSame(100, $stageResult->uid);
    }

    public function testStageSetToolReturnsErrorWhenStageNotAccessible(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('workspaceCheckStageForCurrent')->willReturn(false);
        $GLOBALS['BE_USER'] = $beUser;

        $closure = $this->getRegisteredClosure(
            $this->createStub(RecordService::class),
            $this->createStub(DataHandlerService::class),
            $this->createStub(ConnectionPool::class),
            'workspace_stage_set',
        );
        $stageResult = $closure('pages', 100, -10);

        self::assertInstanceOf(ErrorResult::class, $stageResult);
        self::assertSame('Stage not accessible to current user', $stageResult->error);
    }

    private function createRegistrar(
        ?RecordService $recordService = null,
        ?DataHandlerService $dataHandlerService = null,
        ?ConnectionPool $connectionPool = null,
    ): WorkspaceToolRegistrar {
        return new WorkspaceToolRegistrar(
            $recordService ?? $this->createStub(RecordService::class),
            $dataHandlerService ?? $this->createStub(DataHandlerService::class),
            $connectionPool ?? $this->createStub(ConnectionPool::class),
            new NullLogger(),
        );
    }

    private function getRegisteredClosure(
        RecordService $recordService,
        DataHandlerService $dataHandlerService,
        ?ConnectionPool $connectionPool,
        string $toolName,
    ): \Closure {
        $registrar = $this->createRegistrar($recordService, $dataHandlerService, $connectionPool);

        $builder = Server::builder();
        $registrar->register($builder);

        $tools = $this->getRegisteredTools($builder);
        foreach ($tools as $tool) {
            if (($tool['name'] ?? null) === $toolName) {
                /** @var \Closure $handler */
                $handler = $tool['handler'];

                return $handler;
            }
        }

        self::fail('Tool "' . $toolName . '" was not registered');
    }

    /** @return list<array{handler: \Closure, name: string}> */
    private function getRegisteredTools(Server\Builder $builder): array
    {
        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('tools');
        /** @var list<array{handler: \Closure, name: string}> $tools */
        $tools = $property->getValue($builder);

        return $tools;
    }

    /** @return QueryBuilder&\PHPUnit\Framework\MockObject\Stub */
    private function createWorkspaceQueryBuilder(Result $result): QueryBuilder
    {
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'0'");
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }
}
