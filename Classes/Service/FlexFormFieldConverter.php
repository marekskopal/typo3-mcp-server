<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Mcp\Server\Exception\ToolCallException;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * FlexForm fields (`tt_content.pi_flexform`, …) as JSON objects instead of raw XML (#40).
 *
 * Read: the stored XML becomes `{"<sheet>": {"<field>": value}}`. A section field keeps its native
 * array (`{"el": …}`), since its containers have no flat form.
 *
 * Write: an object in the same shape — or flat, `{"<field>": value}`, resolved against the record's
 * data structure — becomes the array DataHandler accepts for a flex field. Every name is checked against
 * the data structure first, because DataHandler stores unknown keys without complaint. DataHandler merges
 * that array into the stored XML, so an object only has to name the fields it changes. A string is
 * passed through unchanged, so FlexForm XML keeps working.
 */
readonly class FlexFormFieldConverter
{
    /** Tool-description text for the content tools, whose `pi_flexform` is the FlexForm field agents write. */
    public const string HINT = ' A FlexForm field such as pi_flexform is an object: {"<sheet>": {"<field>": value}},'
        . ' e.g. {"sDEF": {"settings.templateLayout": "split"}}; on write a flat {"settings.templateLayout": "split"} works'
        . ' too. Names are checked against the plugin\'s FlexForm, and only the fields named change. XML is still accepted.';

    private const string LANGUAGE_INDEX = 'lDEF';

    private const string VALUE_INDEX = 'vDEF';

    public function __construct(private FlexFormTools $flexFormTools, private TcaSchemaService $tcaSchemaService,)
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toWire(string $table, array $row): array
    {
        foreach ($this->tcaSchemaService->getFlexFields($table) as $field) {
            $value = $row[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $parsed = GeneralUtility::xml2arrayProcess($value);
            if (!is_array($parsed)) {
                continue;
            }

            $row[$field] = $this->sheetsFromNative($parsed);
        }

        return $row;
    }

    /**
     * Whether the fields carry a flex value in object form, i.e. one that needs the record's data structure.
     *
     * @param array<string, mixed> $fields
     */
    public function hasObjectValues(string $table, array $fields): bool
    {
        foreach ($this->tcaSchemaService->getFlexFields($table) as $field) {
            if (is_array($fields[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $fields the values about to be written
     * @param array<mixed> $row the record as stored (empty for a new one); the data structure
     *     is resolved from it with $fields on top, so a CType changed in the same call counts
     * @return array<string, mixed>
     */
    public function toDataHandler(string $table, array $fields, array $row): array
    {
        foreach ($this->tcaSchemaService->getFlexFields($table) as $field) {
            $value = $fields[$field] ?? null;
            if (!is_array($value)) {
                continue;
            }

            $sheets = $this->dataStructureSheets($table, $field, array_merge($row, $fields));
            $fields[$field] = ['data' => $this->nativeFromObject($field, $value, $sheets)];
        }

        return $fields;
    }

    /**
     * @param array<mixed> $native
     * @return array<string, array<string, mixed>>
     */
    private function sheetsFromNative(array $native): array
    {
        $data = $native['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $sheets = [];
        foreach ($data as $sheetName => $sheet) {
            $fields = is_array($sheet) ? ($sheet[self::LANGUAGE_INDEX] ?? null) : null;
            if (!is_array($fields)) {
                continue;
            }

            $values = [];
            foreach ($fields as $fieldName => $fieldValue) {
                $values[(string) $fieldName] = is_array($fieldValue) && array_key_exists(self::VALUE_INDEX, $fieldValue)
                    ? $fieldValue[self::VALUE_INDEX]
                    : $fieldValue;
            }

            $sheets[(string) $sheetName] = $values;
        }

        return $sheets;
    }

    /**
     * @param array<mixed> $value
     * @param array<string, array<string, bool>> $sheets sheet => field => is a section
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function nativeFromObject(string $field, array $value, array $sheets): array
    {
        $bySheet = $this->isSheetKeyed($value, $sheets) ? $value : $this->assignToSheets($field, $value, $sheets);

        $data = [];
        foreach ($bySheet as $sheetName => $sheetValues) {
            $sheetName = (string) $sheetName;
            if (!is_array($sheetValues)) {
                throw new ToolCallException(
                    sprintf('%s.%s must be an object of FlexForm field names and values.', $field, $sheetName),
                );
            }

            foreach ($sheetValues as $fieldName => $fieldValue) {
                $fieldName = (string) $fieldName;
                $isSection = $sheets[$sheetName][$fieldName] ?? null;
                if ($isSection === null) {
                    throw $this->unknownField($field, $sheetName . '.' . $fieldName, $sheets);
                }

                $data[$sheetName][self::LANGUAGE_INDEX][$fieldName] = $this->nativeValue($field, $fieldName, $fieldValue, $isSection);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function nativeValue(string $field, string $fieldName, mixed $value, bool $isSection): array
    {
        if ($isSection) {
            if (!is_array($value)) {
                throw new ToolCallException(
                    sprintf('%s: "%s" is a FlexForm section; pass its native array ({"el": …}).', $field, $fieldName),
                );
            }

            $native = [];
            foreach ($value as $key => $item) {
                $native[(string) $key] = $item;
            }

            return $native;
        }

        $scalar = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            default => throw new ToolCallException(
                sprintf('%s: "%s" takes a single value, got %s.', $field, $fieldName, get_debug_type($value)),
            ),
        };

        return [self::VALUE_INDEX => $scalar];
    }

    /**
     * @param array<mixed> $value
     * @param array<string, array<string, bool>> $sheets
     */
    private function isSheetKeyed(array $value, array $sheets): bool
    {
        foreach ($value as $key => $sheetValues) {
            if (!isset($sheets[(string) $key]) || !is_array($sheetValues)) {
                return false;
            }
        }

        return $value !== [];
    }

    /**
     * @param array<mixed> $value
     * @param array<string, array<string, bool>> $sheets
     * @return array<string, array<string, mixed>>
     */
    private function assignToSheets(string $field, array $value, array $sheets): array
    {
        $bySheet = [];
        foreach ($value as $fieldName => $fieldValue) {
            $fieldName = (string) $fieldName;
            $matches = array_keys(array_filter($sheets, static fn (array $fields): bool => isset($fields[$fieldName])));
            if ($matches === []) {
                throw $this->unknownField($field, $fieldName, $sheets);
            }

            if (count($matches) > 1) {
                throw new ToolCallException(sprintf(
                    '%s: "%s" exists in several sheets (%s); name the sheet, e.g. {"%s": {"%s": …}}.',
                    $field,
                    $fieldName,
                    implode(', ', $matches),
                    $matches[0],
                    $fieldName,
                ));
            }

            $bySheet[$matches[0]][$fieldName] = $fieldValue;
        }

        return $bySheet;
    }

    /** @param array<string, array<string, bool>> $sheets */
    private function unknownField(string $field, string $name, array $sheets): ToolCallException
    {
        $available = [];
        foreach ($sheets as $sheetName => $fields) {
            foreach (array_keys($fields) as $fieldName) {
                $available[] = $sheetName . '.' . $fieldName;
            }
        }

        return new ToolCallException(sprintf(
            '%s: "%s" is not a field of this record\'s FlexForm. Available: %s.',
            $field,
            $name,
            $available === [] ? '(none — this record type has no FlexForm)' : implode(', ', $available),
        ));
    }

    /**
     * The record's data structure as sheet => field => whether the field is a section.
     *
     * @param array<mixed> $row
     * @return array<string, array<string, bool>>
     */
    private function dataStructureSheets(string $table, string $field, array $row): array
    {
        $fieldConfig = $this->tcaSchemaService->getFieldConfig($table, $field);

        try {
            // The trailing TCA argument exists on v14 only, where FlexFormTools needs it to find the default data
            // structure; v13 has no such parameter and reads $GLOBALS['TCA'] itself. The raw TCA rather than a
            // TcaSchema, because TcaSchemaFactory is internal on v13.
            $allTca = $GLOBALS['TCA'] ?? null;
            $tca = is_array($allTca) && is_array($allTca[$table] ?? null) ? $allTca[$table] : [];
            $identifier = $this->flexFormTools->getDataStructureIdentifier(['config' => $fieldConfig], $table, $field, $row, $tca);
            $dataStructure = $this->flexFormTools->parseDataStructureByIdentifier($identifier, $tca);
        } catch (\Throwable $e) {
            throw new ToolCallException(
                sprintf(
                    'Could not resolve the FlexForm data structure of %s.%s for this record (%s). Pass the field as FlexForm XML'
                        . ' instead, or set the record type (e.g. CType) first.',
                    $table,
                    $field,
                    $e::class,
                ),
                1730600010,
                $e,
            );
        }

        $sheets = [];
        $definedSheets = $dataStructure['sheets'] ?? [];
        foreach (is_array($definedSheets) ? $definedSheets : [] as $sheetName => $sheet) {
            $root = is_array($sheet) ? ($sheet['ROOT'] ?? null) : null;
            $elements = is_array($root) ? ($root['el'] ?? null) : null;
            $sheets[(string) $sheetName] = [];
            foreach (is_array($elements) ? $elements : [] as $fieldName => $element) {
                $sheets[(string) $sheetName][(string) $fieldName] = is_array($element) && (bool) ($element['section'] ?? false);
            }
        }

        return $sheets;
    }
}
