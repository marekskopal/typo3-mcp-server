<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class SiteResult
{
    /** @param list<SiteLanguageResult> $languages */
    public function __construct(public string $identifier, public int $rootPageId, public string $base, public array $languages)
    {
    }
}
