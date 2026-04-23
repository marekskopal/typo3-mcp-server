<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class RecordCopiedResult
{
    public bool $copied;

    public function __construct(public int $uid, public int $newUid)
    {
        $this->copied = true;
    }
}
