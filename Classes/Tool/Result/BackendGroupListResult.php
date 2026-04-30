<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BackendGroupListResult
{
    /** @param list<BackendGroupSummaryResult> $records */
    public function __construct(public array $records, public int $total)
    {
    }
}
