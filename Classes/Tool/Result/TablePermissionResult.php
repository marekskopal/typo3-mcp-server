<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class TablePermissionResult
{
    public function __construct(public string $table, public bool $canSelect, public bool $canModify,)
    {
    }
}
