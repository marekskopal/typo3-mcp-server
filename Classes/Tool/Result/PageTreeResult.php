<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class PageTreeResult
{
    /** @param list<array<string, mixed>> $tree each node carries its own `children` list */
    public function __construct(public array $tree, public int $totalNodes,)
    {
    }
}
