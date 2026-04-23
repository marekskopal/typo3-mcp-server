<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class CacheClearedResult
{
    public bool $cleared;

    public function __construct(public string $scope)
    {
        $this->cleared = true;
    }
}
