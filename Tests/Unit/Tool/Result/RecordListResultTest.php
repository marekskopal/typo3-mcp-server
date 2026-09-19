<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Result;

use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordListResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordListResult::class)]
final class RecordListResultTest extends TestCase
{
    public function testLiveQueryCarriesTotalAndOmitsTheWorkspaceKeys(): void
    {
        $result = JsonResult::of(RecordListResult::fromQuery(['records' => [['uid' => 1]], 'total' => 1]));

        self::assertSame([['uid' => 1]], $result['records']);
        self::assertSame(1, $result['total']);
        self::assertArrayNotHasKey('hasMore', $result);
        self::assertArrayNotHasKey('workspaceOverlay', $result);
        self::assertArrayNotHasKey('ignoredFields', $result);
    }

    /** A SQL COUNT cannot be workspace-overlaid, so an overlaid query reports hasMore and no total. */
    public function testOverlaidQueryCarriesHasMoreAndNoTotal(): void
    {
        $result = JsonResult::of(RecordListResult::fromQuery([
            'records' => [],
            'hasMore' => true,
            'workspaceOverlay' => 'workspace 5',
        ]));

        self::assertTrue($result['hasMore']);
        self::assertSame('workspace 5', $result['workspaceOverlay']);
        self::assertArrayNotHasKey('total', $result);
    }

    public function testIgnoredFieldsAreReportedOnlyWhenThereAreAny(): void
    {
        $result = JsonResult::of(RecordListResult::fromQuery(['records' => [], 'total' => 0], ['bogus']));

        self::assertSame(['bogus'], $result['ignoredFields']);
    }
}
