<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileCopiedResult
{
    public bool $copied;

    public function __construct(public string $identifier, public string $targetDirectory)
    {
        $this->copied = true;
    }
}
