<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class TcaTableEntry
{
    public function __construct(public string $table, public string $label)
    {
    }
}
