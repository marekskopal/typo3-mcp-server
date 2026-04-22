<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileReferenceAddedResult
{
    /** @param list<int> $referenceUids */
    public function __construct(
        public string $table,
        public int $uid,
        public string $fieldName,
        public int $referencesCreated,
        public array $referenceUids,
    ) {
    }
}
