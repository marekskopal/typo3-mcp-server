<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

/**
 * A single record read. The fields are serialized at the top level, with `translations` appended
 * when the tool resolved them, which is the shape the read tools have always returned.
 */
readonly class RecordResult implements JsonSerializable
{
    /**
     * @param array<string, mixed> $record
     * @param list<array<string, mixed>>|null $translations
     */
    public function __construct(public array $record, public ?array $translations = null,)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        if ($this->translations === null) {
            return $this->record;
        }

        return [...$this->record, 'translations' => $this->translations];
    }
}
