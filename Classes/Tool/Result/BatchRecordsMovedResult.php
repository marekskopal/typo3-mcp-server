<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BatchRecordsMovedResult
{
    /** @param list<int> $uids */
    public function __construct(public array $uids, public int $count, public int $target)
    {
    }
}
