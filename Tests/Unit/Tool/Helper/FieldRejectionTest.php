<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Helper;

use MarekSkopal\MsMcpServer\Tool\Helper\FieldRejection;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldRejection::class)]
final class FieldRejectionTest extends TestCase
{
    public function testItNamesTheTableAndTheFieldsThatWereDropped(): void
    {
        $exception = FieldRejection::noValidFields('pages', ['bogus', 'nope']);

        self::assertInstanceOf(ToolCallException::class, $exception);
        self::assertStringContainsString('table "pages"', $exception->getMessage());
        self::assertStringContainsString('Ignored, not writable: bogus, nope.', $exception->getMessage());
        self::assertStringContainsString('table_schema', $exception->getMessage());
    }

    public function testItOmitsTheIgnoredListWhenThePayloadWasEmpty(): void
    {
        self::assertStringNotContainsString('Ignored', FieldRejection::noValidFields('pages', [])->getMessage());
    }
}
