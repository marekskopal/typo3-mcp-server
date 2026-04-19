<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

readonly class SiteLanguageService
{
    public function __construct(private SiteFinder $siteFinder)
    {
    }

    /** @return list<array{languageId: int, title: string, locale: string, flagIdentifier: string, enabled: bool, hreflang: string}> */
    public function getLanguagesForPage(int $pageId): array
    {
        $site = $this->siteFinder->getSiteByPageId($pageId);
        $languages = $site->getAllLanguages();

        return array_values(array_map(
            static fn (SiteLanguage $language): array => [
                'languageId' => $language->getLanguageId(),
                'title' => $language->getTitle(),
                'locale' => (string) $language->getLocale(),
                'flagIdentifier' => $language->getFlagIdentifier(),
                'enabled' => $language->isEnabled(),
                'hreflang' => $language->getHreflang(),
            ],
            $languages,
        ));
    }
}
