<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class DirectoryMovedResult
{
    public bool $moved;

    public function __construct(public string $identifier, public string $targetDirectory)
    {
        $this->moved = true;
    }
}
