<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class DirectoryCreatedResult
{
    public function __construct(public string $name, public string $identifier,)
    {
    }
}
