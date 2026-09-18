<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Result;

use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCountResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordCountResult::class)]
final class RecordCountResultTest extends TestCase
{
    public function testAnExactCountOmitsTheExactKey(): void
    {
        $result = JsonResult::of(new RecordCountResult('pages', 12));

        self::assertSame(['table' => 'pages', 'count' => 12], $result);
    }

    public function testAnInexactCountSaysSoRatherThanReadAsExact(): void
    {
        $result = JsonResult::of(new RecordCountResult('pages', 500, false, ['bogus']));

        self::assertFalse($result['exact']);
        self::assertSame(['bogus'], $result['ignoredFields']);
    }
}
