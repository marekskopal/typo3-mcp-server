<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BackendUserListResult
{
    /** @param list<BackendUserSummaryResult> $records */
    public function __construct(public array $records, public int $total)
    {
    }
}
