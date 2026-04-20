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

#[CoversClass(RecordService::class)]
final class RecordServiceTest extends TestCase
{
    public function testFindByUidReturnsRecordWhenFound(): void
    {
        $expectedRecord = ['uid' => 1, 'title' => 'Test Page'];

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn($expectedRecord);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'1'");
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

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'999'");
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

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $countQueryBuilder = $this->createStub(QueryBuilder::class);
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('where')->willReturnSelf();
        $countQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $countQueryBuilder->method('createNamedParameter')->willReturn("'0'");
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createStub(QueryBuilder::class);
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('where')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $listQueryBuilder->method('createNamedParameter')->willReturn("'0'");
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

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $countQueryBuilder = $this->createStub(QueryBuilder::class);
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('where')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $countQueryBuilder->method('createNamedParameter')->willReturn("'0'");
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createStub(QueryBuilder::class);
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('where')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $listQueryBuilder->method('createNamedParameter')->willReturn("'0'");
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

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $countQueryBuilder = $this->createStub(QueryBuilder::class);
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $countQueryBuilder->method('createNamedParameter')->willReturn("'%Hello%'");
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createStub(QueryBuilder::class);
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $listQueryBuilder->method('createNamedParameter')->willReturn("'%Hello%'");
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool);
        $result = $service->search('pages', ['title' => 'Hello'], 20, 0, ['uid', 'title']);

        self::assertSame($expectedRecords, $result['records']);
        self::assertSame(1, $result['total']);
    }

    public function testSearchWithPidFilter(): void
    {
        $countResult = $this->createStub(Result::class);
        $countResult->method('fetchOne')->willReturn(0);

        $listResult = $this->createStub(Result::class);
        $listResult->method('fetchAllAssociative')->willReturn([]);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $countQueryBuilder = $this->createStub(QueryBuilder::class);
        $countQueryBuilder->method('count')->willReturnSelf();
        $countQueryBuilder->method('from')->willReturnSelf();
        $countQueryBuilder->method('andWhere')->willReturnSelf();
        $countQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $countQueryBuilder->method('createNamedParameter')->willReturn("'0'");
        $countQueryBuilder->method('executeQuery')->willReturn($countResult);

        $listQueryBuilder = $this->createStub(QueryBuilder::class);
        $listQueryBuilder->method('select')->willReturnSelf();
        $listQueryBuilder->method('from')->willReturnSelf();
        $listQueryBuilder->method('andWhere')->willReturnSelf();
        $listQueryBuilder->method('setMaxResults')->willReturnSelf();
        $listQueryBuilder->method('setFirstResult')->willReturnSelf();
        $listQueryBuilder->method('orderBy')->willReturnSelf();
        $listQueryBuilder->method('expr')->willReturn($expressionBuilder);
        $listQueryBuilder->method('createNamedParameter')->willReturn("'0'");
        $listQueryBuilder->method('executeQuery')->willReturn($listResult);

        $callCount = 0;
        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')
            ->willReturnCallback(function () use (&$callCount, $listQueryBuilder, $countQueryBuilder): QueryBuilder {
                $callCount++;

                return $callCount === 1 ? $listQueryBuilder : $countQueryBuilder;
            });

        $service = new RecordService($connectionPool);
        $result = $service->search('pages', ['title' => 'Test'], 20, 0, ['uid', 'title'], 5);

        self::assertSame([], $result['records']);
        self::assertSame(0, $result['total']);
    }

    public function testFindTranslationsReturnsTranslationRecords(): void
    {
        $expectedRows = [
            ['uid' => 87, 'sys_language_uid' => 1],
            ['uid' => 88, 'sys_language_uid' => 2],
        ];

        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn($expectedRows);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'42'");
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
}
