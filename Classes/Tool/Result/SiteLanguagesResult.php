<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

use JsonSerializable;

/**
 * The site languages for a page. Serializes to the bare list the tool has always returned, so the
 * DTO is a typing improvement on the PHP side only and no client sees a different payload.
 */
readonly class SiteLanguagesResult implements JsonSerializable
{
    /** @param list<array{languageId: int, title: string, locale: string, flagIdentifier: string, enabled: bool, hreflang: string}> $languages */
    public function __construct(public array $languages)
    {
    }

    /** @return list<array{languageId: int, title: string, locale: string, flagIdentifier: string, enabled: bool, hreflang: string}> */
    public function jsonSerialize(): array
    {
        return $this->languages;
    }
}
