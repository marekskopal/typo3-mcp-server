<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class WorkspaceChangesResult
{
    /** @param array<string, list<array{uid: int, pid: int, liveUid: int, state: string, stage: int}>> $tables changed records per table */
    public function __construct(public int $workspaceId, public array $tables,)
    {
    }
}
