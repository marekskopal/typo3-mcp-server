<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Service\RecordService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(RecordService::class)]
final class RecordServiceTest extends TestCase
{
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
        $record = $service->findByUid('pages', 999, ['uid', 'title']);

        self::assertNull($record);
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
        $result = $service->search('pages', ['title' => ['operator' => 'like', 'value' => 'Test']], 20, 0, ['uid', 'title'], 5);

        self::assertSame([], $result['records']);
        self::assertSame(0, $result['total']);
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
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

        $service = new RecordService($connectionPool);
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

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool);
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

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $service = new RecordService($connectionPool);
        $references = $service->findFileReferences('tt_content', 999, 'image');

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

        $service = new RecordService($connectionPool);
        $translations = $service->findTranslations('pages', 42, 'sys_language_uid', 'l10n_parent');

        self::assertCount(2, $translations);
        self::assertSame(87, $translations[0]['uid']);
        self::assertSame(1, $translations[0]['sys_language_uid']);
        self::assertSame(88, $translations[1]['uid']);
        self::assertSame(2, $translations[1]['sys_language_uid']);
    }

    /** @return QueryBuilder&\PHPUnit\Framework\MockObject\Stub */
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
