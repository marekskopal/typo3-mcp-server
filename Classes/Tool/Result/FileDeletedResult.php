<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileDeletedResult
{
    public bool $deleted;

    public function __construct(public string $identifier)
    {
        $this->deleted = true;
    }
}
