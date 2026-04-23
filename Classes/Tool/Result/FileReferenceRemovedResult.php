<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

readonly class FileReferenceRemovedResult
{
    /** @param list<int> $referenceUids */
    public function __construct(public int $referencesRemoved, public array $referenceUids,)
    {
    }
}
