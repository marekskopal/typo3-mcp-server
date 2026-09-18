<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Result;

use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordResult::class)]
final class RecordResultTest extends TestCase
{
    public function testFieldsAreSerializedAtTheTopLevel(): void
    {
        self::assertSame(
            ['uid' => 5, 'title' => 'Home'],
            JsonResult::of(new RecordResult(['uid' => 5, 'title' => 'Home'])),
        );
    }

    public function testTranslationsAreAppendedOnlyWhenResolved(): void
    {
        $result = JsonResult::of(new RecordResult(['uid' => 5], [['uid' => 6, 'sys_language_uid' => 1]]));

        self::assertSame(5, $result['uid']);
        self::assertSame([['uid' => 6, 'sys_language_uid' => 1]], $result['translations']);
    }
}
