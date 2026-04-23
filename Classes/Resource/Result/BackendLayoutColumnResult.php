<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class BackendLayoutColumnResult
{
    public function __construct(public int $colPos, public string $name)
    {
    }
}
