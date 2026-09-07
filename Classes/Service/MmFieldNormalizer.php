<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Mcp\Exception\ToolCallException;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Turns the wire format of a many-to-many field into what DataHandler expects.
 *
 * A client may send an MM field as a JSON array of UIDs (`[20, 21]`) or as the comma-separated
 * string DataHandler takes (`"20,21"`); DataHandler only understands the latter, so the array form
 * is joined and both forms are validated entry by entry. An empty array or string clears the
 * relation. Invalid entries are rejected with a {@see ToolCallException} naming the field — before
 * DataHandler sees them, because it would otherwise silently drop what it cannot interpret.
 *
 * Fields that are not MM relations pass through untouched, so this is safe to apply to every
 * write and every table.
 */
readonly class MmFieldNormalizer
{
    public function __construct(private TcaSchemaService $tcaSchemaService)
    {
    }

    /**
     * @param array<string, mixed> $fields the field data about to be written
     * @return array<string, mixed> the same data with every MM field as a comma-separated UID string
     */
    public function normalize(string $table, array $fields): array
    {
        $mmFields = $this->tcaSchemaService->getMMFields($table);
        if ($mmFields === []) {
            return $fields;
        }

        foreach ($mmFields as $fieldName => $config) {
            if (!array_key_exists($fieldName, $fields)) {
                continue;
            }

            $fields[$fieldName] = $this->normalizeValue($fieldName, $fields[$fieldName], $config);
        }

        return $fields;
    }

    /** @param array<mixed> $config */
    private function normalizeValue(string $fieldName, mixed $value, array $config): string
    {
        $entries = $this->entries($fieldName, $value);
        if ($entries === []) {
            return '';
        }

        $isGroup = ($config['type'] ?? null) === 'group';
        $allowedTables = $this->allowedTables($config);
        $multiTable = $isGroup && ($allowedTables === null || count($allowedTables) > 1);

        $normalized = [];
        foreach ($entries as $entry) {
            $normalized[] = $this->normalizeEntry($fieldName, $entry, $isGroup, $multiTable, $allowedTables);
        }

        return implode(',', $normalized);
    }

    /**
     * Splits the accepted input shapes into their entries: a JSON array, a comma-separated string,
     * or a single integer as a one-element list. Null clears, like an empty array. A JSON object
     * (an associative array after decoding) is rejected rather than having its values read as UIDs.
     *
     * @return list<mixed>
     */
    private function entries(string $fieldName, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw $this->invalid($fieldName, 'got a JSON object instead of an array');
            }

            return $value;
        }

        if (is_int($value)) {
            return [$value];
        }

        if (is_string($value)) {
            $entries = [];
            foreach (explode(',', $value) as $entry) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $entries[] = $entry;
                }
            }

            return $entries;
        }

        throw $this->invalid($fieldName, sprintf('got %s', get_debug_type($value)));
    }

    /**
     * @param bool $isGroup group fields also take the `table_uid` form; a select never does
     * @param bool $multiTable a group field allowing several tables, where a bare uid is ambiguous
     * @param list<string>|null $allowedTables null when the field allows every table (`*`)
     */
    private function normalizeEntry(string $fieldName, mixed $entry, bool $isGroup, bool $multiTable, ?array $allowedTables): string
    {
        if (is_int($entry) || (is_string($entry) && MathUtility::canBeInterpretedAsInteger($entry))) {
            if ((int) $entry <= 0) {
                throw $this->invalid($fieldName, sprintf('"%s" is not a positive integer', (string) $entry));
            }

            if ($multiTable) {
                throw $this->invalid(
                    $fieldName,
                    sprintf(
                        '"%s" is ambiguous because the field allows several tables; use the table_uid form (e.g. "tt_content_%d")',
                        (string) $entry,
                        (int) $entry,
                    ),
                );
            }

            return (string) (int) $entry;
        }

        if ($isGroup && is_string($entry) && preg_match('/^([a-zA-Z0-9_]+)_(\d+)$/', $entry, $matches) === 1 && (int) $matches[2] > 0) {
            if ($allowedTables !== null && !in_array($matches[1], $allowedTables, true)) {
                throw $this->invalid(
                    $fieldName,
                    sprintf('table "%s" is not allowed here (allowed: %s)', $matches[1], implode(', ', $allowedTables)),
                );
            }

            return $matches[1] . '_' . (int) $matches[2];
        }

        $shown = is_scalar($entry) ? (string) $entry : get_debug_type($entry);

        throw $this->invalid($fieldName, sprintf('"%s" is not a UID', $shown));
    }

    /**
     * The tables a relation entry may point to: the select's foreign table, or the group's allowed
     * list. Null means unrestricted (`allowed = '*'`), where any `table_uid` is accepted.
     *
     * @param array<mixed> $config
     * @return list<string>|null
     */
    private function allowedTables(array $config): ?array
    {
        if (($config['type'] ?? null) === 'group') {
            $allowed = $config['allowed'] ?? '';
            if (!is_string($allowed) || trim($allowed) === '*') {
                return null;
            }

            return $this->tcaSchemaService->parseAllowedTables($allowed);
        }

        $foreignTable = $config['foreign_table'] ?? null;

        return is_string($foreignTable) && $foreignTable !== '' ? [$foreignTable] : [];
    }

    private function invalid(string $fieldName, string $detail): ToolCallException
    {
        return new ToolCallException(
            sprintf(
                'Field "%s" is a many-to-many relation and expects a list of UIDs (e.g. [20, 21] or "20,21"); %s.',
                $fieldName,
                $detail,
            ),
            1725900001,
        );
    }
}
