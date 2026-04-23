<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileReferenceListResult
{
    /** @param list<array<string, mixed>> $references */
    public function __construct(
        public string $table,
        public int $uid,
        public string $fieldName,
        public int $total,
        public array $references,
    ) {
    }
}
