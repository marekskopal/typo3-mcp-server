<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Helper;

/** @internal */
class RowField
{
    /** @param array<string, mixed> $row */
    public static function asInt(array $row, string $key, int $default = 0): int
    {
        $value = $row[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) || is_float($value) || is_bool($value)) {
            return (int) $value;
        }

        return $default;
    }

    /** @param array<string, mixed> $row */
    public static function asString(array $row, string $key, string $default = ''): string
    {
        $value = $row[$key] ?? $default;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /** @param array<string, mixed> $row */
    public static function asBool(array $row, string $key, bool $default = false): bool
    {
        $value = $row[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        return $default;
    }

    /**
     * Parse a comma-separated stringified id list into a list of ints.
     *
     * @param array<string, mixed> $row
     * @return list<int>
     */
    public static function asIntList(array $row, string $key): array
    {
        $stringValue = self::asString($row, $key);
        if (trim($stringValue) === '') {
            return [];
        }

        return array_values(array_map(
            static fn(string $item): int => (int) $item,
            array_filter(
                array_map('trim', explode(',', $stringValue)),
                static fn(string $item): bool => $item !== '',
            ),
        ));
    }
}
