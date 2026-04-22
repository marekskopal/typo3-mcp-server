<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class RecordDeletedResult
{
    public bool $deleted;

    public function __construct(public int $uid)
    {
        $this->deleted = true;
    }
}
