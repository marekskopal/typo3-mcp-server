<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Helper;

use MarekSkopal\MsMcpServer\Tool\Helper\UidListParser;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UidListParser::class)]
final class UidListParserTest extends TestCase
{
    public function testParseReturnsPositiveIntegers(): void
    {
        self::assertSame([1, 2, 3], UidListParser::parse('1, 2,3'));
    }

    public function testParseFiltersOutNonPositiveAndEmpty(): void
    {
        self::assertSame([5], UidListParser::parse('0,-2,,5'));
    }

    public function testParseThrowsWhenExceedingMaximum(): void
    {
        $uids = implode(',', range(1, UidListParser::MAX_UIDS + 1));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Too many UIDs');

        UidListParser::parse($uids);
    }
}
