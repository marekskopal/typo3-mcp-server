<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class RecordDeletedResult
{
    /**
     * True only when the record was actually removed. A dry run reports `deleted: false` alongside
     * `dryRun: true`, so a preview cannot be mistaken for a completed deletion by a client that
     * only looks at this flag.
     */
    public bool $deleted;

    public function __construct(public int $uid, public bool $dryRun = false)
    {
        $this->deleted = !$dryRun;
    }
}
