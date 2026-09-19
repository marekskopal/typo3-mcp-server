<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class StorageListResult
{
    /** @param list<array{uid: int, name: string, fullAccess: bool, mounts: list<array{path: string, title: string, readOnly: bool}>}> $storages */
    public function __construct(public array $storages)
    {
    }
}
