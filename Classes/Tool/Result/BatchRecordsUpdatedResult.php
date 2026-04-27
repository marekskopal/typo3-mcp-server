<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class BatchRecordsUpdatedResult
{
    /**
     * @param list<int> $uids
     * @param list<string> $updatedFields
     * @param list<string> $ignoredFields
     * @param list<int> $skippedUids
     */
    public function __construct(
        public array $uids,
        public int $count,
        public array $updatedFields,
        public array $ignoredFields = [],
        public array $skippedUids = [],
    ) {
    }
}
