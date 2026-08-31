<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BatchRecordsUpdatedResult
{
    /**
     * @param list<int> $uids records updated, or — when $dryRun is true — the records that would be
     * @param list<string> $updatedFields fields written, or the fields that would be written
     * @param list<string> $ignoredFields requested fields that are not writable on this table
     * @param list<int> $skippedUids UIDs that do not exist in the table and were left alone
     * @param bool $dryRun true when nothing was written and this is a preview of the change
     */
    public function __construct(
        public array $uids,
        public int $count,
        public array $updatedFields,
        public array $ignoredFields = [],
        public array $skippedUids = [],
        public bool $dryRun = false,
    ) {
    }
}
