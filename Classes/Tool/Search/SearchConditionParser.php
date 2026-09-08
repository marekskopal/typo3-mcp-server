<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Exception\ToolCallException;
use const JSON_THROW_ON_ERROR;

/**
 * Turns the decoded `search` object into validated `{operator, value}` conditions.
 *
 * Accepted shapes per field:
 * - a string/number: `{"title": "hello"}` — LIKE
 * - `true` / `false`: `{"hidden": true}` — eq 1 / eq 0
 * - `null`: `{"l10n_source": null}` — IS NULL
 * - a list: `{"uid": [1, 2]}` — IN
 * - an operator-keyed object: `{"TSconfig": {"like": "tx_news"}}`
 * - the long form: `{"uid": {"op": "gt", "value": "10"}}`
 *
 * Anything else is rejected. It used to fall through to `LIKE '%%'`, which matches every
 * non-NULL row — a client that wrote the natural `{"TSconfig":{"like":"msasl"}}` got back every
 * page with any TSconfig at all, and nothing in the response said the condition had been dropped.
 *
 * @internal
 */
class SearchConditionParser
{
    /**
     * Parse a JSON-decoded search array into validated search conditions.
     *
     * @param array<string, mixed> $data
     * @param list<string> $allowedFields
     * @return array<string, array{operator: string, value: string}>
     */
    public static function fromArray(array $data, array $allowedFields): array
    {
        $conditions = [];
        foreach ($data as $field => $value) {
            $field = (string) $field;
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }

            $conditions[$field] = self::parseCondition($field, $value);
        }

        return $conditions;
    }

    /** @return array{operator: string, value: string} */
    private static function parseCondition(string $field, mixed $value): array
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return ['operator' => 'like', 'value' => (string) $value];
        }

        if (is_bool($value)) {
            return ['operator' => 'eq', 'value' => $value ? '1' : '0'];
        }

        if ($value === null) {
            return ['operator' => 'null', 'value' => ''];
        }

        if (!is_array($value)) {
            throw self::unrecognisedShape($field);
        }

        if (array_is_list($value)) {
            return ['operator' => 'in', 'value' => self::conditionValue($field, 'in', $value)];
        }

        if (array_key_exists('op', $value)) {
            $op = $value['op'];
            // Fail fast with a client-visible message: ToolCallException is relayed verbatim to the
            // MCP client, so a typo'd operator is reported instead of surfacing as "internal error".
            if (!is_string($op) || !in_array($op, RecordService::SUPPORTED_OPERATORS, true)) {
                throw new ToolCallException(
                    sprintf(
                        'Unsupported search operator "%s". Supported operators: %s.',
                        is_scalar($op) ? (string) $op : gettype($op),
                        implode(', ', RecordService::SUPPORTED_OPERATORS),
                    ),
                    1718100002,
                );
            }

            return ['operator' => $op, 'value' => self::conditionValue($field, $op, $value['value'] ?? '')];
        }

        // {"like": "msasl"} — a single key that names the operator.
        if (count($value) === 1) {
            $op = (string) array_key_first($value);
            if (in_array($op, RecordService::SUPPORTED_OPERATORS, true)) {
                return ['operator' => $op, 'value' => self::conditionValue($field, $op, $value[$op])];
            }
        }

        throw self::unrecognisedShape($field);
    }

    /**
     * The operand as the string RecordService binds. `in` also accepts a list, which is joined
     * with commas; `null` / `notNull` take no operand, so whatever was given is ignored.
     */
    private static function conditionValue(string $field, string $operator, mixed $value): string
    {
        if ($operator === 'null' || $operator === 'notNull') {
            return '';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($operator === 'in' && is_array($value) && array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                if (!is_string($item) && !is_int($item) && !is_float($item)) {
                    throw self::unrecognisedShape($field);
                }

                $items[] = (string) $item;
            }

            return implode(',', $items);
        }

        throw self::unrecognisedShape($field);
    }

    private static function unrecognisedShape(string $field): ToolCallException
    {
        return new ToolCallException(
            sprintf(
                'Condition on field "%1$s" has an unrecognised shape. Use a plain value for LIKE (%2$s),'
                    . ' an operator-keyed object (%3$s), a list for IN (%4$s), or %5$s. Supported operators: %6$s.',
                $field,
                json_encode([$field => 'hello'], JSON_THROW_ON_ERROR),
                json_encode([$field => ['like' => 'hello']], JSON_THROW_ON_ERROR),
                json_encode([$field => [1, 2]], JSON_THROW_ON_ERROR),
                json_encode([$field => ['op' => 'gt', 'value' => '10']], JSON_THROW_ON_ERROR),
                implode(', ', RecordService::SUPPORTED_OPERATORS),
            ),
            1725700001,
        );
    }
}
