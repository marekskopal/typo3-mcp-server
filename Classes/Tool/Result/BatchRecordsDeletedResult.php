<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BatchRecordsDeletedResult
{
    /**
     * @param list<int> $uids
     * @param list<int> $skippedUids
     */
    public function __construct(public array $uids, public int $count, public array $skippedUids = [])
    {
    }
}
