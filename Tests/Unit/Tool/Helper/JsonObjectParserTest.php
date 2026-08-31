<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Helper;

use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonObjectParser::class)]
class JsonObjectParserTest extends TestCase
{
    public function testParsesJsonObject(): void
    {
        self::assertSame(
            ['title' => 'Home', 'hidden' => 0],
            JsonObjectParser::parse('{"title":"Home","hidden":0}', 'fields'),
        );
    }

    public function testParsesEmptyObject(): void
    {
        // `{}` decodes to [] and is indistinguishable from an empty list — it must be accepted,
        // the callers report "no valid fields" themselves.
        self::assertSame([], JsonObjectParser::parse('{}', 'fields'));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function nonObjectProvider(): iterable
    {
        yield 'number' => ['5', 'a number'];
        yield 'float' => ['1.5', 'a number'];
        yield 'string' => ['"Home"', 'a string'];
        yield 'boolean' => ['true', 'a boolean'];
        yield 'null' => ['null', 'null'];
        yield 'list' => ['[1,2]', 'an array'];
    }

    /**
     * Valid JSON that is not an object used to reach array_intersect_key()/array_keys() and raise
     * a TypeError, reported to the client as the opaque "An internal error occurred".
     */
    #[DataProvider('nonObjectProvider')]
    public function testRejectsValidJsonThatIsNotAnObject(string $json, string $expectedDescription): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('fields must be a JSON object, got ' . $expectedDescription . '.');

        JsonObjectParser::parse($json, 'fields');
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('search must be a JSON object, but is not valid JSON');

        JsonObjectParser::parse('{"title":"Home"', 'search');
    }

    public function testNamesTheOffendingParameter(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('payload must be a JSON object, got a number.');

        JsonObjectParser::parse('42', 'payload');
    }
}
