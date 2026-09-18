<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

/**
 * The workspaces accessible to the caller. Serializes to the bare list the tool has always
 * returned, so no client sees a different payload.
 */
readonly class WorkspaceListResult implements JsonSerializable
{
    /** @param list<array{uid: int, title: string, access: string}> $workspaces */
    public function __construct(public array $workspaces)
    {
    }

    /** @return list<array{uid: int, title: string, access: string}> */
    public function jsonSerialize(): array
    {
        return $this->workspaces;
    }
}
