<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

/**
 * One workspace's metadata plus the caller's access level. The record's own fields stay at the top
 * level with `access` appended, which is the shape `workspace_get` has always returned.
 */
readonly class WorkspaceResult implements JsonSerializable
{
    /** @param array<string, mixed> $record */
    public function __construct(public array $record, public string $access,)
    {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [...$this->record, 'access' => $this->access];
    }
}
