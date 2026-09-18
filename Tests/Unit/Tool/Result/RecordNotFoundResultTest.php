<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Result;

use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordNotFoundResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordNotFoundResult::class)]
final class RecordNotFoundResultTest extends TestCase
{
    /** A miss is data, and it says so with `found`, rather than an "error" key on a successful call. */
    public function testItSerializesAsDataWithAFoundFlag(): void
    {
        self::assertSame(
            ['found' => false, 'table' => 'pages', 'uid' => 999, 'message' => 'Page not found'],
            JsonResult::of(new RecordNotFoundResult('pages', 999, 'Page not found')),
        );
    }
}
