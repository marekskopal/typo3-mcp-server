<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileReferenceAddedResult
{
    /**
     * @param list<int> $referenceUids
     * @param list<string> $updated the `metadata` fields written to the references
     * @param list<string> $ignoredFields `metadata` fields that are not writable and were dropped
     */
    public function __construct(
        public string $table,
        public int $uid,
        public string $fieldName,
        public int $referencesCreated,
        public array $referenceUids,
        public array $updated = [],
        public array $ignoredFields = [],
    ) {
    }
}
