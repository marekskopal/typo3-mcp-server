<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class RecordTranslatedResult
{
    /**
     * @param list<string> $updated The translated fields set on the new record from `fields`
     * @param list<string> $ignoredFields Names in `fields` that were not writable and were dropped
     */
    public function __construct(
        public int $uid,
        public string $table,
        public int $targetLanguageId,
        public array $updated = [],
        public array $ignoredFields = [],
    ) {
    }
}
