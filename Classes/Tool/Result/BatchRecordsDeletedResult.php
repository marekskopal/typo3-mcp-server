<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BatchRecordsDeletedResult
{
    /**
     * @param list<int> $uids records deleted, or — when $dryRun is true — the records that would be
     * @param list<int> $skippedUids UIDs that do not exist in the table and were left alone
     * @param bool $dryRun true when nothing was written and this is a preview of the change
     */
    public function __construct(public array $uids, public int $count, public array $skippedUids = [], public bool $dryRun = false,)
    {
    }
}
