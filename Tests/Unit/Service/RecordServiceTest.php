<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\WorkspaceContextService;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\PagePermissionRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(RecordService::class)]
final class RecordServiceTest extends TestCase
{
    protected function setUp(): void
    {
        for ($i = 0; $i < 10; $i++) {
            GeneralUtility::addInstance(DeletedRestriction::class, $this->createStub(DeletedRestriction::class));
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
    }

    public function testFindByUidReturnsRecordWhenFound(): void
    {
        $expectedRecord = ['uid' => 1, 'title' => 'Test Page'];

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($expectedRecord);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $record = $service->findByUid('pages', 1, ['uid', 'title']);

        self::assertSame($expectedRecord, $record);
    }

    public function testFindByUidReturnsNullWhenNotFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $record = $service->findByUid('pages', 999, ['uid', 'title']);

        self::assertNull($record);
    }

    public function testFindByUidAppliesDeletedRestriction(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['uid' => 1, 'title' => 'Test']);

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->expects(self::atLeastOnce())->method('add');

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'0'");
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $service->findByUid('pages', 1, ['uid', 'title']);
    }

    public function testFindExistingUidsReturnsOnlyExistingUids(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([['uid' => 1], ['uid' => 3]]);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $existing = $service->findExistingUids('pages', [1, 2, 3]);

        self::assertSame([1, 3], $existing);
    }

    public function testFindExistingUidsReturnsEmptyArrayForEmptyInput(): void
    {
        $connectionPool = $this->createStub(ConnectionPool::class);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $existing = $service->findExistingUids('pages', []);

        self::assertSame([], $existing);
    }

    public function testFindByPidReturnsRecordsAndTotal(): void
    {
        $expectedRecords = [
            ['uid' => 1, 'title' => 'Page 1'],
            ['uid' => 2, 'title' => 'Page 2'],
        ];

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(5);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn($expectedRecords);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('where')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('where')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->findByPid('pages', 0, 20, 0, ['uid', 'title']);

        self::assertSame($expectedRecords, $result['records']);
        self::assertSame(5, $result['total']);
    }

    public function testFindByPidAcceptsOptionalLanguageFilter(): void
    {
        $expectedRecords = [['uid' => 1, 'title' => 'Page 1']];

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(1);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn($expectedRecords);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('where')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('where')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->findByPid('pages', 0, 20, 0, ['uid', 'title'], 0, 'sys_language_uid');

        self::assertSame($expectedRecords, $result['records']);
        self::assertSame(1, $result['total']);
    }

    public function testSearchReturnsRecordsAndTotal(): void
    {
        $expectedRecords = [
            ['uid' => 1, 'title' => 'Hello World'],
        ];

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(1);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn($expectedRecords);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->search('pages', ['title' => ['operator' => 'like', 'value' => 'Hello']], 20, 0, ['uid', 'title']);

        self::assertSame($expectedRecords, $result['records']);
        self::assertSame(1, $result['total']);
    }

    public function testSearchWithPidFilter(): void
    {
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->search('pages', ['title' => ['operator' => 'like', 'value' => 'Test']], 20, 0, ['uid', 'title'], 5);

        self::assertSame([], $result['records']);
        self::assertSame(0, $result['total']);
    }

    public function testSearchEscapesLikeWildcards(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('fetchAllAssociative')->willReturn([]);

        $captured = [];

        $expr = $this->createStub(ExpressionBuilder::class);
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('escapeLikeWildcards')
            ->willReturnCallback(static fn(string $v): string => str_replace(['%', '_'], ['\\%', '\\_'], $v));
        $queryBuilder->method('createNamedParameter')
            ->willReturnCallback(function (mixed $value) use (&$captured): string {
                $captured[] = $value;

                return "'p'";
            });
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('setFirstResult')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $service->search('pages', ['title' => ['operator' => 'like', 'value' => '50%_x']], 20, 0, ['uid', 'title']);

        self::assertContains('%50\\%\\_x%', $captured);
    }

    public function testFindByPidClampsNegativeOffsetToZero(): void
    {
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);
        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);

        $capturedOffsets = [];

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setFirstResult')
            ->willReturnCallback(function (int $offset) use (&$capturedOffsets, $queryBuilder): QueryBuilder {
                $capturedOffsets[] = $offset;

                return $queryBuilder;
            });
        $callCount = 0;
        $queryBuilder->method('executeQuery')
            ->willReturnCallback(function () use (&$callCount, $listResult, $countResult): Result {
                $callCount++;

                return $callCount === 1 ? $countResult : $listResult;
            });

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $service->findByPid('pages', 1, 20, -50, ['uid', 'title']);

        self::assertSame([0], $capturedOffsets);
    }

    public function testSearchWithEqOperator(): void
    {
        $expectedRecords = [['uid' => 1, 'title' => 'Home']];

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(1);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn($expectedRecords);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->search('pages', ['title' => ['operator' => 'eq', 'value' => 'Home']], 20, 0, ['uid', 'title']);

        self::assertSame($expectedRecords, $result['records']);
        self::assertSame(1, $result['total']);
    }

    public function testSearchWithNullOperator(): void
    {
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->search('pages', ['title' => ['operator' => 'null', 'value' => '']], 20, 0, ['uid', 'title']);

        self::assertSame([], $result['records']);
        self::assertSame(0, $result['total']);
    }

    public function testSearchWithInOperator(): void
    {
        $expectedRecords = [['uid' => 1, 'title' => 'Page 1'], ['uid' => 3, 'title' => 'Page 3']];

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(2);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn($expectedRecords);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->search('pages', ['uid' => ['operator' => 'in', 'value' => '1,3']], 20, 0, ['uid', 'title']);

        self::assertSame($expectedRecords, $result['records']);
        self::assertSame(2, $result['total']);
    }

    public function testSearchWithCustomOrderBy(): void
    {
        $expectedRecords = [['uid' => 2, 'title' => 'Alpha'], ['uid' => 1, 'title' => 'Beta']];

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(2);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn($expectedRecords);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->search(
            'pages',
            ['title' => ['operator' => 'like', 'value' => '']],
            20,
            0,
            ['uid', 'title'],
            null,
            'title',
            'DESC',
        );

        self::assertSame($expectedRecords, $result['records']);
        self::assertSame(2, $result['total']);
    }

    public function testSearchWithInvalidOrderDirectionDefaultsToAsc(): void
    {
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $result = $service->search(
            'pages',
            ['title' => ['operator' => 'like', 'value' => 'Test']],
            20,
            0,
            ['uid', 'title'],
            null,
            null,
            'INVALID',
        );

        self::assertSame([], $result['records']);
        self::assertSame(0, $result['total']);
    }

    public function testFindFileReferencesReturnsReferences(): void
    {
        $expectedRows = [
            ['uid' => 201, 'uid_local' => 10, 'title' => 'Logo', 'description' => '', 'alternative' => '', 'link' => '', 'crop' => '', 'autoplay' => 0, 'sorting_foreign' => 1],
            ['uid' => 202, 'uid_local' => 11, 'title' => '', 'description' => '', 'alternative' => '', 'link' => '', 'crop' => '', 'autoplay' => 0, 'sorting_foreign' => 2],
        ];

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($expectedRows);
        // The parent-record visibility gate loads the record first; the same stub serves both queries.
        $result->method('fetchAssociative')->willReturn(['uid' => 100]);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $references = $service->findFileReferences('tt_content', 100, 'image');

        self::assertCount(2, $references);
        self::assertSame(201, $references[0]['uid']);
        self::assertSame(10, $references[0]['uid_local']);
        self::assertSame('Logo', $references[0]['title']);
    }

    public function testFindFileReferencesReturnsEmptyArrayWhenNoneFound(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $result->method('fetchAssociative')->willReturn(['uid' => 999]);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $references = $service->findFileReferences('tt_content', 999, 'image');

        self::assertSame([], $references);
    }

    public function testFindFileReferencesReturnsEmptyArrayWhenParentRecordIsNotVisible(): void
    {
        // Parent record lookup yields nothing (e.g. hidden by the page-permission constraint):
        // reference metadata must not leak.
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);
        $result->method('fetchAllAssociative')->willReturn([['uid' => 201]]);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $references = $service->findFileReferences('tt_content', 100, 'image');

        self::assertSame([], $references);
    }

    public function testFindTranslationsReturnsTranslationRecords(): void
    {
        $expectedRows = [
            ['uid' => 87, 'sys_language_uid' => 1],
            ['uid' => 88, 'sys_language_uid' => 2],
        ];

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($expectedRows);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());
        $translations = $service->findTranslations('pages', 42, 'sys_language_uid', 'l10n_parent');

        self::assertCount(2, $translations);
        self::assertSame(87, $translations[0]['uid']);
        self::assertSame(1, $translations[0]['sys_language_uid']);
        self::assertSame(88, $translations[1]['uid']);
        self::assertSame(2, $translations[1]['sys_language_uid']);
    }

    public function testSearchAppliesPagePermissionRestrictionForNonAdminOnPagesTable(): void
    {
        $addedToListRestrictions = [];

        $listRestrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $listRestrictions->method('add')
            ->willReturnCallback(function (object $restriction) use (&$addedToListRestrictions, $listRestrictions) {
                $addedToListRestrictions[] = $restriction;

                return $listRestrictions;
            });

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $listQueryBuilder = $this->createStub(QueryBuilder::class);
        $listQueryBuilder->method('getRestrictions')->willReturn($listRestrictions);
        $listQueryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $listQueryBuilder->method('createNamedParameter')->willReturn("'0'");
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createEditorPermissionService());
        $service->search('pages', ['title' => ['operator' => 'eq', 'value' => 'x']], 20, 0, ['uid', 'title']);

        $pagePermissionRestrictions = array_filter(
            $addedToListRestrictions,
            static fn(object $restriction): bool => $restriction instanceof PagePermissionRestriction,
        );
        self::assertNotEmpty($pagePermissionRestrictions);
    }

    public function testSearchConstrainsNonPageTableToAccessiblePagesForNonAdmin(): void
    {
        $requestedTables = [];
        $listAndWhereCalled = false;

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $listQueryBuilder = $this->createQueryBuilderStub();
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);
        $listQueryBuilder->method('andWhere')
            ->willReturnCallback(function () use (&$listAndWhereCalled, $listQueryBuilder): QueryBuilder {
                $listAndWhereCalled = true;

                return $listQueryBuilder;
            });

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        // Subquery builder for the `pages` table used to resolve accessible pages.
        $pagesQueryBuilder = $this->createQueryBuilderStub();
        $pagesQueryBuilder->method('select')->willReturnSelf();
        $pagesQueryBuilder->method('from')->willReturnSelf();
        $pagesQueryBuilder->method('where')->willReturnSelf();
        $pagesQueryBuilder->method('getSQL')->willReturn('SELECT uid FROM pages WHERE PERMS_CLAUSE');

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function (string $table) use (
                &$callCount,
                &$requestedTables,
                $listQueryBuilder,
                $countQueryBuilder,
                $pagesQueryBuilder,
            ): QueryBuilder {
                $requestedTables[] = $table;
                $callCount++;

                return match ($callCount) {
                    1 => $listQueryBuilder,
                    2 => $countQueryBuilder,
                    default => $pagesQueryBuilder,
                };
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createEditorPermissionService());
        $service->search('tt_content', ['header' => ['operator' => 'eq', 'value' => 'x']], 20, 0, ['uid', 'header']);

        // The non-page table read must build a `pages` subquery and constrain the main query by it.
        self::assertContains('pages', $requestedTables);
        self::assertTrue($listAndWhereCalled);
    }

    public function testSearchKeepsRootLevelRecordsVisibleForNonAdminOnNonPageTable(): void
    {
        $capturedOrParts = null;

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('eq')
            ->willReturnCallback(static fn(string $field, mixed $value): string => $field . ' = ' . $value);
        $expressionBuilder->method('in')
            ->willReturnCallback(
                static fn(string $field, string|array $value): string => $field . ' IN (' . (is_string($value) ? $value : '') . ')',
            );
        $expressionBuilder->method('and')
            ->willReturnCallback(static fn(...$parts): CompositeExpression => CompositeExpression::and(...$parts));
        $expressionBuilder->method('or')
            ->willReturnCallback(static function (...$parts) use (&$capturedOrParts): CompositeExpression {
                $capturedOrParts = $parts;

                return CompositeExpression::or(...$parts);
            });

        $listQueryBuilder = $this->createStub(QueryBuilder::class);
        $listQueryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));
        $listQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $listQueryBuilder->method('createNamedParameter')
            ->willReturnCallback(static fn(mixed $value): string => is_array($value) ? implode(',', $value) : (string) $value);
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $pagesQueryBuilder = $this->createQueryBuilderStub();
        $pagesQueryBuilder->method('select')->willReturnSelf();
        $pagesQueryBuilder->method('from')->willReturnSelf();
        $pagesQueryBuilder->method('getSQL')->willReturn('SELECT uid FROM pages WHERE PERMS_CLAUSE');

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (
                &$callCount,
                $listQueryBuilder,
                $countQueryBuilder,
                $pagesQueryBuilder,
            ): QueryBuilder {
                $callCount++;

                return match ($callCount) {
                    1 => $listQueryBuilder,
                    2 => $countQueryBuilder,
                    default => $pagesQueryBuilder,
                };
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createEditorPermissionService());
        $service->search('sys_redirect', [], 20, 0, ['uid', 'source_path']);

        // Root-level records (rootLevel tables like sys_redirect live at pid 0) must stay readable:
        // the constraint has to be `pid = 0 OR (pid in accessible pages within the webmounts)`,
        // not the page subquery alone.
        self::assertIsArray($capturedOrParts);
        self::assertSame('pid = 0', $capturedOrParts[0]);
        self::assertSame('((pid IN (SELECT uid FROM pages WHERE PERMS_CLAUSE)) AND (pid IN (1,2)))', (string) $capturedOrParts[1]);
    }

    public function testFindExistingUidsConstrainsNonPageTableToAccessiblePagesForNonAdmin(): void
    {
        [$requestedTables, $andWhereCalled] = $this->runNonAdminHelperQuery(
            static fn(RecordService $service) => $service->findExistingUids('tt_content', [1, 2, 3]),
        );

        // UID probing must honour the page-permission constraint, otherwise batch-tool "skipped"
        // responses become an existence oracle for records on restricted pages.
        self::assertContains('pages', $requestedTables);
        self::assertTrue($andWhereCalled);
    }

    public function testFindTranslationsConstrainsNonPageTableToAccessiblePagesForNonAdmin(): void
    {
        [$requestedTables, $andWhereCalled] = $this->runNonAdminHelperQuery(
            static fn(RecordService $service) => $service->findTranslations('tt_content', 42, 'sys_language_uid', 'l18n_parent'),
        );

        self::assertContains('pages', $requestedTables);
        self::assertTrue($andWhereCalled);
    }

    /**
     * Run a RecordService helper as a non-admin against stubbed query builders and report which
     * tables were queried and whether the main query was constrained via andWhere().
     *
     * @param callable(RecordService): mixed $call
     * @return array{list<string>, bool}
     */
    private function runNonAdminHelperQuery(callable $call): array
    {
        $requestedTables = [];
        $andWhereCalled = false;

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $result->method('fetchOne')->willReturn(0);

        $mainQueryBuilder = $this->createQueryBuilderStub();
        $mainQueryBuilder->method('select')->willReturnSelf();
        $mainQueryBuilder->method('from')->willReturnSelf();
        $mainQueryBuilder->method('where')->willReturnSelf();
        $mainQueryBuilder->method('orderBy')->willReturnSelf();
        $mainQueryBuilder->method('executeQuery')->willReturn($result);
        $mainQueryBuilder->method('andWhere')
            ->willReturnCallback(function () use (&$andWhereCalled, $mainQueryBuilder): QueryBuilder {
                $andWhereCalled = true;

                return $mainQueryBuilder;
            });

        $pagesQueryBuilder = $this->createQueryBuilderStub();
        $pagesQueryBuilder->method('select')->willReturnSelf();
        $pagesQueryBuilder->method('from')->willReturnSelf();
        $pagesQueryBuilder->method('getSQL')->willReturn('SELECT uid FROM pages WHERE PERMS_CLAUSE');

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function (string $table) use (
                &$callCount,
                &$requestedTables,
                $mainQueryBuilder,
                $pagesQueryBuilder,
            ): QueryBuilder {
                $requestedTables[] = $table;
                $callCount++;

                return $callCount === 1 ? $mainQueryBuilder : $pagesQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createEditorPermissionService());
        $call($service);

        return [$requestedTables, $andWhereCalled];
    }

    public function testFindByUidConfinesPagesReadsToWebmountsForNonAdmin(): void
    {
        $andWhereConditions = $this->capturePagesAndWhereConditions([7, 9]);

        // ACL SHOW alone is not enough — the page must also sit inside the user's webmounts.
        self::assertContains('uid IN (7,9)', $andWhereConditions);
    }

    public function testFindByUidDeniesPagesReadsWithoutWebmountsForNonAdmin(): void
    {
        $andWhereConditions = $this->capturePagesAndWhereConditions([]);

        // A non-admin without any webmount can reach no page at all.
        self::assertContains('1 = 0', $andWhereConditions);
    }

    /**
     * Run a non-admin findByUid('pages', …) with the given webmount page ids and return every
     * condition string passed to andWhere().
     *
     * @param list<int> $webmountPageIds
     * @return list<string>
     */
    private function capturePagesAndWhereConditions(array $webmountPageIds): array
    {
        $andWhereConditions = [];

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $expressionBuilder->method('in')
            ->willReturnCallback(
                static fn(string $field, string|array $value): string => $field . ' IN (' . (is_string($value) ? $value : '') . ')',
            );
        $expressionBuilder->method('eq')
            ->willReturnCallback(static fn(string $field, mixed $value): string => $field . ' = ' . $value);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')
            ->willReturnCallback(static fn(mixed $value): string => is_array($value) ? implode(',', $value) : (string) $value);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('andWhere')
            ->willReturnCallback(
                function (string|CompositeExpression ...$conditions) use (&$andWhereConditions, $queryBuilder): QueryBuilder {
                    foreach ($conditions as $condition) {
                        $andWhereConditions[] = (string) $condition;
                    }

                    return $queryBuilder;
                },
            );

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(true);
        $permissionService->method('isAdmin')->willReturn(false);
        $permissionService->method('getUserAspect')->willReturn(new UserAspect());
        $permissionService->method('getWebmountPageIds')->willReturn($webmountPageIds);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $permissionService);
        $service->findByUid('pages', 7, ['uid', 'title']);

        return $andWhereConditions;
    }

    public function testCountAppliesPagePermissionRestrictionForNonAdminOnPagesTable(): void
    {
        $addedRestrictions = [];

        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('add')
            ->willReturnCallback(function (object $restriction) use (&$addedRestrictions, $restrictions) {
                $addedRestrictions[] = $restriction;

                return $restrictions;
            });

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(3);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn("'0'");
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($countResult);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createEditorPermissionService());
        $count = $service->count('pages');

        self::assertSame(['count' => 3, 'exact' => true], $count);
        $pagePermissionRestrictions = array_filter(
            $addedRestrictions,
            static fn(object $restriction): bool => $restriction instanceof PagePermissionRestriction,
        );
        self::assertNotEmpty($pagePermissionRestrictions);
    }

    public function testCountConstrainsNonPageTableToAccessiblePagesForNonAdmin(): void
    {
        $requestedTables = [];
        $andWhereCalled = false;

        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $countQueryBuilder = $this->createQueryBuilderStub();
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);
        $countQueryBuilder->method('andWhere')
            ->willReturnCallback(function () use (&$andWhereCalled, $countQueryBuilder): QueryBuilder {
                $andWhereCalled = true;

                return $countQueryBuilder;
            });

        $pagesQueryBuilder = $this->createQueryBuilderStub();
        $pagesQueryBuilder->method('select')->willReturnSelf();
        $pagesQueryBuilder->method('from')->willReturnSelf();
        $pagesQueryBuilder->method('getSQL')->willReturn('SELECT uid FROM pages WHERE PERMS_CLAUSE');

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function (string $table) use (
                &$callCount,
                &$requestedTables,
                $countQueryBuilder,
                $pagesQueryBuilder,
            ): QueryBuilder {
                $requestedTables[] = $table;
                $callCount++;

                return $callCount === 1 ? $countQueryBuilder : $pagesQueryBuilder;
            });

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createEditorPermissionService());
        $service->count('tt_content', 5);

        // count() must honour the same page-permission constraint as search()/findByPid():
        // a `pages` subquery is built and the count query is constrained by it.
        self::assertContains('pages', $requestedTables);
        self::assertTrue($andWhereCalled);
    }

    public function testFindByUidThrowsWhenTableNotSelectable(): void
    {
        $connectionPool = $this->createStub(ConnectionPool::class);

        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(false);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $permissionService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Access denied: you do not have read permission for table "be_users".');

        $service->findByUid('be_users', 1, ['uid']);
    }

    public function testFindExistingUidsThrowsWhenTableNotSelectable(): void
    {
        $service = new RecordService(
            $this->createStub(ConnectionPool::class),
            new WorkspaceContextService(),
            $this->createDenyingPermissionService(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1718100000);

        $service->findExistingUids('be_users', [1, 2]);
    }

    public function testFindFileReferencesThrowsWhenTableNotSelectable(): void
    {
        $service = new RecordService(
            $this->createStub(ConnectionPool::class),
            new WorkspaceContextService(),
            $this->createDenyingPermissionService(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1718100000);

        $service->findFileReferences('be_users', 1, 'avatar');
    }

    public function testFindTranslationsThrowsWhenTableNotSelectable(): void
    {
        $service = new RecordService(
            $this->createStub(ConnectionPool::class),
            new WorkspaceContextService(),
            $this->createDenyingPermissionService(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1718100000);

        $service->findTranslations('be_users', 1, 'sys_language_uid', 'l10n_parent');
    }

    public function testSearchThrowsOnUnknownOperator(): void
    {
        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool, new WorkspaceContextService(), $this->createAllowingPermissionService());

        // ToolCallException reaches the MCP client verbatim, so the message must name the operator.
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1718100001);
        $this->expectExceptionMessageMatches('/Unsupported search operator "regexp"\. Supported operators: eq, /');

        $service->search('pages', ['title' => ['operator' => 'regexp', 'value' => 'x']], 20, 0, ['uid', 'title']);
    }

    /**
     * A workspace context standing in a non-live workspace on a workspace-aware table, whose
     * overlay drops the rows in $hiddenUids — what a DELETE_PLACEHOLDER does in a real workspace.
     *
     * @param list<int> $hiddenUids
     */
    /**
     * setMaxResults() used to be applied *before* the overlay, so a page came back short while
     * later records still existed, and the separate COUNT was never overlaid — `total` claimed 5
     * where only 3 rows are visible.
     */
    public function testSearchPaginatesOverTheOverlaidSetInAWorkspace(): void
    {
        $rows = [
            ['uid' => 1, 'title' => 'One'],
            ['uid' => 2, 'title' => 'Two'],
            ['uid' => 3, 'title' => 'Three'],
            ['uid' => 4, 'title' => 'Four'],
            ['uid' => 5, 'title' => 'Five'],
        ];

        $service = new RecordService(
            $this->createOverlayConnectionPool($rows),
            $this->createWorkspaceContext([2, 4]),
            $this->createAllowingPermissionService(),
        );

        $result = $service->search('tt_content', [], 2, 0, ['uid', 'title']);

        self::assertSame([$rows[0], $rows[2]], $result['records']);
        self::assertTrue($result['hasMore']);
        self::assertArrayNotHasKey('total', $result);
        self::assertArrayHasKey('workspaceOverlay', $result);
    }

    /**
     * The second page must continue where the overlaid first page ended. Paging over the raw
     * ordering used to step past visible records, silently skipping them.
     */
    public function testSearchSecondPageDoesNotSkipOverlaidRecords(): void
    {
        $rows = [
            ['uid' => 1, 'title' => 'One'],
            ['uid' => 2, 'title' => 'Two'],
            ['uid' => 3, 'title' => 'Three'],
            ['uid' => 4, 'title' => 'Four'],
            ['uid' => 5, 'title' => 'Five'],
        ];

        $service = new RecordService(
            $this->createOverlayConnectionPool($rows),
            $this->createWorkspaceContext([2, 4]),
            $this->createAllowingPermissionService(),
        );

        $result = $service->search('tt_content', [], 2, 2, ['uid', 'title']);

        self::assertSame([$rows[4]], $result['records']);
        self::assertFalse($result['hasMore']);
    }

    public function testFindByPidPaginatesOverTheOverlaidSetInAWorkspace(): void
    {
        $rows = [
            ['uid' => 1, 'title' => 'One'],
            ['uid' => 2, 'title' => 'Two'],
            ['uid' => 3, 'title' => 'Three'],
        ];

        $service = new RecordService(
            $this->createOverlayConnectionPool($rows),
            $this->createWorkspaceContext([2]),
            $this->createAllowingPermissionService(),
        );

        $result = $service->findByPid('tt_content', 10, 20, 0, ['uid', 'title']);

        self::assertSame([$rows[0], $rows[2]], $result['records']);
        self::assertFalse($result['hasMore']);
        self::assertArrayNotHasKey('total', $result);
    }

    /** A SQL COUNT cannot be overlaid, so it used to disagree with what search() returned. */
    public function testCountCountsOverlaidRowsInAWorkspace(): void
    {
        $rows = [
            ['uid' => 1, 'title' => 'One'],
            ['uid' => 2, 'title' => 'Two'],
            ['uid' => 3, 'title' => 'Three'],
        ];

        $service = new RecordService(
            $this->createOverlayConnectionPool($rows),
            $this->createWorkspaceContext([2]),
            $this->createAllowingPermissionService(),
        );

        self::assertSame(['count' => 2, 'exact' => true], $service->count('tt_content'));
    }

    /**
     * @param list<array<string, mixed>> $rows every query returns these rows
     */
    private function createOverlayConnectionPool(array $rows): ConnectionPool
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        $result->method('fetchOne')->willReturn(count($rows));

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('setFirstResult')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $connectionPool;
    }

    private function createWorkspaceContext(array $hiddenUids): WorkspaceContextService
    {
        $context = $this->createStub(WorkspaceContextService::class);
        $context->method('isLive')->willReturn(false);
        $context->method('isTableWorkspaceAware')->willReturn(true);
        $context->method('getCurrentWorkspaceId')->willReturn(1);
        $context->method('overlayMany')->willReturnCallback(
            static fn(string $table, array $rows): array => array_values(array_filter(
                $rows,
                static fn(array $row): bool => !in_array($row['uid'], $hiddenUids, true),
            )),
        );

        return $context;
    }

    private function createAllowingPermissionService(): PermissionService
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(true);
        // Admin path: no page-permission constraint is added, so the query expectations in these
        // tests reflect the unrestricted query. Non-admin behaviour is covered separately below.
        $permissionService->method('isAdmin')->willReturn(true);

        return $permissionService;
    }

    private function createDenyingPermissionService(): PermissionService
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(false);

        return $permissionService;
    }

    private function createEditorPermissionService(): PermissionService
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(true);
        $permissionService->method('isAdmin')->willReturn(false);
        $permissionService->method('getUserAspect')->willReturn(new UserAspect());
        $permissionService->method('getWebmountPageIds')->willReturn([1, 2]);

        return $permissionService;
    }

    /** @return QueryBuilder&Stub */
    private function createQueryBuilderStub(): QueryBuilder
    {
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'0'");

        return $queryBuilder;
    }
}
