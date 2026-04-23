<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class BackendLayoutResult
{
    /** @param list<BackendLayoutColumnResult> $columns */
    public function __construct(
        public string $identifier,
        public string $title,
        public string $description,
        public array $columns,
        public BackendLayoutStructureResult $structure,
    ) {
    }
}
