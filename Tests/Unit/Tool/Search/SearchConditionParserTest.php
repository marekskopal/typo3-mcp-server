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

    public function testOperatorKeyedShorthandIsAccepted(): void
    {
        // {"TSconfig":{"like":"msasl"}} — the shape a client reaches for first. It used to fall
        // through to LIKE '%%' and match every non-NULL row (TMS report, asl-brno 2026-09-07).
        $conditions = SearchConditionParser::fromArray(
            ['TSconfig' => ['like' => 'msasl'], 'uid' => ['gt' => 10]],
            ['TSconfig', 'uid'],
        );

        self::assertSame(
            [
                'TSconfig' => ['operator' => 'like', 'value' => 'msasl'],
                'uid' => ['operator' => 'gt', 'value' => '10'],
            ],
            $conditions,
        );
    }

    public function testShorthandWithListValueBecomesIn(): void
    {
        $conditions = SearchConditionParser::fromArray(['uid' => ['in' => [1, 2, 3]]], ['uid']);

        self::assertSame(['uid' => ['operator' => 'in', 'value' => '1,2,3']], $conditions);
    }

    public function testBareListDefaultsToIn(): void
    {
        $conditions = SearchConditionParser::fromArray(['uid' => [4, 5]], ['uid']);

        self::assertSame(['uid' => ['operator' => 'in', 'value' => '4,5']], $conditions);
    }

    public function testBareBooleanBecomesEquality(): void
    {
        $conditions = SearchConditionParser::fromArray(['hidden' => true, 'deleted' => false], ['hidden', 'deleted']);

        self::assertSame(
            ['hidden' => ['operator' => 'eq', 'value' => '1'], 'deleted' => ['operator' => 'eq', 'value' => '0']],
            $conditions,
        );
    }

    public function testBareNullBecomesIsNull(): void
    {
        $conditions = SearchConditionParser::fromArray(['l10n_source' => null], ['l10n_source']);

        self::assertSame(['l10n_source' => ['operator' => 'null', 'value' => '']], $conditions);
    }

    public function testUnrecognisedObjectShapeIsRejectedInsteadOfMatchingEverything(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1725700001);
        $this->expectExceptionMessageMatches('/Condition on field "TSconfig" has an unrecognised shape/');

        SearchConditionParser::fromArray(['TSconfig' => ['contains' => 'msasl']], ['TSconfig']);
    }

    public function testObjectWithSeveralOperatorKeysIsRejected(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1725700001);

        SearchConditionParser::fromArray(['uid' => ['gt' => 1, 'lt' => 9]], ['uid']);
    }

    public function testShorthandWithNonScalarValueIsRejected(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1725700001);

        SearchConditionParser::fromArray(['title' => ['like' => ['nested' => 'x']]], ['title']);
    }
}
