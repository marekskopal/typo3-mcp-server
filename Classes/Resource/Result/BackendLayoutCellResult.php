<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class BackendLayoutCellResult
{
    public function __construct(public ?int $colPos, public ?string $name, public int $colspan, public int $rowspan)
    {
    }
}
