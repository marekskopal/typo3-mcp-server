<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class BackendLayoutStructureResult
{
    /** @param list<list<BackendLayoutCellResult>> $rows */
    public function __construct(public int $colCount, public int $rowCount, public array $rows)
    {
    }
}
