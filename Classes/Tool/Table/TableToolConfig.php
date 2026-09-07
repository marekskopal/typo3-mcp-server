<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table;

/**
 * Everything a generated table tool needs to know about the table it operates on.
 *
 * Replaces the nine copies of a `array{label: string, prefix: string, listFields: list<string>, …}`
 * docblock that used to be pasted onto every `register*Tool()` method: one type instead of nine
 * annotations, and PHPStan checks it for real rather than trusting a comment.
 *
 * @internal
 */
final readonly class TableToolConfig
{
    /**
     * @param list<string> $listFields fields returned by the list tool when the caller names none
     * @param list<string> $readFields fields the get tool returns, and the set a caller may select from
     * @param list<string> $writableFields fields create/update accept; everything else is reported as ignored
     * @param string|null $languageField TCA `languageField`, or null when the table is not translatable
     * @param string|null $transOrigPointerField TCA `transOrigPointerField`, or null as above
     * @param string $noun what one row is called in tool text — "record" for most tables, but the
     *                     scheduler's rows are "tasks", and "Scheduler task record" reads wrong
     * @param list<string> $mmFields the many-to-many relation fields among the table's columns; they are
     *                               read and written as UID lists, and the tool text says so
     */
    public function __construct(
        public string $tableName,
        public string $label,
        public string $prefix,
        public array $listFields,
        public array $readFields,
        public array $writableFields,
        public ?string $languageField = null,
        public ?string $transOrigPointerField = null,
        public string $noun = 'record',
        public array $mmFields = [],
    ) {
    }

    /** "News record", "redirect record", "Scheduler task". */
    public function subject(): string
    {
        return $this->label . ' ' . $this->noun;
    }

    /** Sentence-initial form of {@see subject()}, for a message that starts with it. */
    public function subjectSentenceStart(): string
    {
        return ucfirst($this->subject());
    }

    public function toolName(string $suffix): string
    {
        return $this->prefix . '_' . $suffix;
    }

    /** True when the table carries translations, so list/create expose a sysLanguageUid parameter. */
    public function isTranslatable(): bool
    {
        return $this->languageField !== null;
    }

    /** The writable fields for a tool description, MM relations marked as `name (uid list)`. */
    public function writableFieldList(): string
    {
        return implode(', ', array_map(
            fn(string $field): string => in_array($field, $this->mmFields, true) ? $field . ' (uid list)' : $field,
            $this->writableFields,
        ));
    }

    /** Sentence for the get/list descriptions naming the fields that come back as UID lists; empty without MM fields. */
    public function mmReadHint(): string
    {
        $readable = array_values(array_intersect($this->readFields, $this->mmFields));
        if ($readable === []) {
            return '';
        }

        return ' Many-to-many relation fields (' . implode(', ', $readable) . ') are returned as lists of related UIDs.';
    }

    /**
     * Sentence explaining the `(uid list)` marker, for the create/update descriptions. Empty when no
     * writable field is an MM relation, so tables without one keep their description unchanged.
     */
    public function mmFieldHint(): string
    {
        if (array_intersect($this->writableFields, $this->mmFields) === []) {
            return '';
        }

        return ' Fields marked (uid list) are many-to-many relations: pass a JSON array of UIDs'
            . ' (e.g. [20, 21]) or a comma-separated string ("20,21"); an empty list clears the relation.';
    }
}
