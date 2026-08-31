<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Search;

use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use Mcp\Exception\ToolCallException;

/**
 * Shared parameter handling for the search tools.
 *
 * `record_search`, `record_count`, `pages_search` and `content_search` each validated `search`
 * and `orderBy` on their own and had drifted apart: the two title/header tools silently turned a
 * malformed JSON object into a LIKE on the raw literal and silently ignored an unknown `orderBy`,
 * so a client got an empty (or wrongly sorted) result set with no hint that its query was wrong.
 * One implementation, so they cannot drift again.
 *
 * @internal
 */
class SearchParamResolver
{
    /**
     * @param list<string> $allowedFields
     * @return string|null the field to sort by, or null when no sorting was requested
     */
    public static function resolveOrderBy(string $orderBy, array $allowedFields): ?string
    {
        if ($orderBy === '') {
            return null;
        }

        if (!in_array($orderBy, $allowedFields, true)) {
            throw new ToolCallException(
                sprintf(
                    'Invalid orderBy field: %s. Allowed fields: %s.',
                    $orderBy,
                    implode(', ', array_unique($allowedFields)),
                ),
                1718100005,
            );
        }

        return $orderBy;
    }

    public static function normalizeOrderDirection(string $orderDirection): string
    {
        return in_array($orderDirection, ['ASC', 'DESC'], true) ? $orderDirection : 'ASC';
    }

    /**
     * Parses the `search` parameter into validated conditions.
     *
     * A value that opens with `{` or `[` is meant to be JSON, so a parse failure is reported
     * rather than degraded into a LIKE on the literal string. Anything else is a plain-text term
     * for $fallbackField; tools whose `search` is JSON-only pass null and always take the JSON
     * path, matching what they did before.
     *
     * @param list<string> $allowedFields
     * @param string|null $fallbackField field to LIKE-match a plain-text term on, or null when the
     *                                   parameter accepts JSON only
     * @return array{conditions: array<string, array{operator: string, value: string}>, ignoredFields: list<string>}
     */
    public static function parseSearch(string $search, array $allowedFields, ?string $fallbackField = null): array
    {
        $trimmed = trim($search);
        $looksLikeJson = str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');

        if ($fallbackField !== null && !$looksLikeJson) {
            return [
                'conditions' => [$fallbackField => ['operator' => 'like', 'value' => $search]],
                'ignoredFields' => [],
            ];
        }

        if ($trimmed === '') {
            return ['conditions' => [], 'ignoredFields' => []];
        }

        $data = JsonObjectParser::parse($search, 'search');

        return [
            'conditions' => SearchConditionParser::fromArray($data, $allowedFields),
            'ignoredFields' => array_values(array_diff(array_map('strval', array_keys($data)), $allowedFields)),
        ];
    }
}
