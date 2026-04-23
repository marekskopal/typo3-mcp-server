<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource\Result;

readonly class SiteLanguageResult
{
    public function __construct(
        public int $languageId,
        public string $title,
        public string $locale,
        public string $flagIdentifier,
        public bool $enabled,
        public string $hreflang,
    ) {
    }
}
