<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Search;

use MarekSkopal\MsMcpServer\Tool\Search\SearchConditionParser;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchConditionParser::class)]
final class SearchConditionParserTest extends TestCase
{
    public function testParsesExplicitOperatorCondition(): void
    {
        $conditions = SearchConditionParser::fromArray(
            ['title' => ['op' => 'eq', 'value' => 'Home']],
            ['title'],
        );

        self::assertSame(['title' => ['operator' => 'eq', 'value' => 'Home']], $conditions);
    }

    public function testBareStringDefaultsToLike(): void
    {
        $conditions = SearchConditionParser::fromArray(['title' => 'Home'], ['title']);

        self::assertSame(['title' => ['operator' => 'like', 'value' => 'Home']], $conditions);
    }

    public function testIgnoresFieldsNotInAllowList(): void
    {
        $conditions = SearchConditionParser::fromArray(['password' => 'x'], ['title']);

        self::assertSame([], $conditions);
    }

    public function testThrowsClientVisibleErrorForUnknownOperator(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1718100002);
        $this->expectExceptionMessageMatches('/Unsupported search operator "regexp"\. Supported operators: eq, /');

        SearchConditionParser::fromArray(['title' => ['op' => 'regexp', 'value' => 'x']], ['title']);
    }

    public function testThrowsClientVisibleErrorForNonStringOperator(): void
    {
        // A non-string op used to silently degrade to operator '' — it must be rejected instead.
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1718100002);

        SearchConditionParser::fromArray(['title' => ['op' => 5, 'value' => 'x']], ['title']);
    }
}
