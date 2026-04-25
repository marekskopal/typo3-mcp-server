<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

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
            $val = $value['value'] ?? '';

            return [
                'operator' => is_string($op) ? $op : '',
                'value' => is_string($val) || is_int($val) || is_float($val) ? (string) $val : '',
            ];
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return ['operator' => 'like', 'value' => (string) $value];
        }

        return ['operator' => 'like', 'value' => ''];
    }
}
