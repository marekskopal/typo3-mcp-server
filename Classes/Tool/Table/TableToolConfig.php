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
    ) {
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

    public function writableFieldList(): string
    {
        return implode(', ', $this->writableFields);
    }
}
