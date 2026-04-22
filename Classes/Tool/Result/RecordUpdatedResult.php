<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class RecordUpdatedResult
{
    /**
     * @param list<string> $updated
     * @param list<string> $ignoredFields
     */
    public function __construct(public int $uid, public array $updated, public array $ignoredFields = [],)
    {
    }
}
