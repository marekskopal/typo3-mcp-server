<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class DirectoryListResult
{
    /**
     * @param list<array{name: string, identifier: string, size: int, mimeType: string, extension: string, modificationTime: int}> $files
     * @param list<array{name: string, identifier: string, modificationTime: int}> $directories
     */
    public function __construct(public array $files, public array $directories, public int $totalFiles, public int $totalDirectories,)
    {
    }
}
