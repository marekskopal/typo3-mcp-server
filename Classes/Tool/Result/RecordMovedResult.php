<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class RecordMovedResult
{
    public bool $moved;

    public function __construct(public int $uid, public int $target)
    {
        $this->moved = true;
    }
}
