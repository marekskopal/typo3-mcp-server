<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileSearchResult
{
    /** @param list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, storage: int}> $files */
    public function __construct(public array $files, public int $total,)
    {
    }
}
