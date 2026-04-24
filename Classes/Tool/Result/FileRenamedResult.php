<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileRenamedResult
{
    public bool $renamed;

    public function __construct(public string $identifier, public string $newName)
    {
        $this->renamed = true;
    }
}
