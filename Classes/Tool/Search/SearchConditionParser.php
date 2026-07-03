<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Exception\ToolCallException;

/** @internal */
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
            if (!in_array($field, $allowedFields, true)) {
                continue;
            }

            $conditions[$field] = self::parseCondition($value);
        }

        return $conditions;
    }

    /** @return array{operator: string, value: string} */
    private static function parseCondition(mixed $value): array
    {
        if (is_array($value) && isset($value['op'])) {
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

            $val = $value['value'] ?? '';

            return [
                'operator' => $op,
                'value' => is_string($val) || is_int($val) || is_float($val) ? (string) $val : '',
            ];
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return ['operator' => 'like', 'value' => (string) $value];
        }

        return ['operator' => 'like', 'value' => ''];
    }
}
