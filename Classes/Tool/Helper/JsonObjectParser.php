<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

use Mcp\Exception\ToolCallException;
use const JSON_THROW_ON_ERROR;

/**
 * Decodes a tool's JSON-object string parameter (`fields`, `search`, …).
 *
 * `JSON_THROW_ON_ERROR` guarantees *valid JSON*, not a JSON **object**: `"5"` decodes to an
 * int, `"null"` to null and `"[1,2]"` to a list. The `array_intersect_key()` / `array_keys()`
 * that follows every one of these call sites then raises a `TypeError`, which the error proxy
 * reports as the opaque "An internal error occurred while executing this tool." — leaving an
 * AI client with no idea what it got wrong and no way to self-correct.
 *
 * `ToolCallException` is relayed verbatim to the client instead, naming the parameter and what
 * arrived, the same way `SearchConditionParser` reports an unsupported operator.
 *
 * @internal
 */
class JsonObjectParser
{
    /** @return array<string, mixed> */
    public static function parse(string $json, string $paramName): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ToolCallException(
                sprintf('%s must be a JSON object, but is not valid JSON: %s.', $paramName, $e->getMessage()),
                1718100003,
                $e,
            );
        }

        // An empty object decodes to [], which is indistinguishable from an empty list — accept it;
        // a non-empty list is a real mistake and is worth reporting.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ToolCallException(
                sprintf('%s must be a JSON object, got %s.', $paramName, self::describe($decoded)),
                1718100004,
            );
        }

        /** @var array<string, mixed> $result */
        $result = $decoded;

        return $result;
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'an array',
            is_bool($value) => 'a boolean',
            is_int($value), is_float($value) => 'a number',
            is_string($value) => 'a string',
            default => 'null',
        };
    }
}
