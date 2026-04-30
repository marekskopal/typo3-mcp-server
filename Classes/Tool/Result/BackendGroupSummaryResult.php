<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BackendGroupSummaryResult
{
    /** @param list<int> $subgroup */
    public function __construct(
        public int $uid,
        public string $title,
        public string $description,
        public bool $hidden,
        public array $subgroup,
    ) {
    }
}
