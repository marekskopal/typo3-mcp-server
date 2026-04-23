<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Resource\Result\SiteLanguageResult;
use MarekSkopal\MsMcpServer\Resource\Result\SiteResult;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ResourceReadException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use const JSON_THROW_ON_ERROR;

readonly class SiteConfigurationResource
{
    public function __construct(private SiteFinder $siteFinder, private LoggerInterface $logger)
    {
    }

    #[McpResource(
        uri: 'typo3://sites',
        name: 'site_configuration',
        description: 'All configured TYPO3 sites with their root page, base URL, and available languages.',
        mimeType: 'application/json',
    )]
    public function execute(): string
    {
        try {
            $sites = $this->siteFinder->getAllSites();

            $result = array_values(array_map(
                static fn(Site $site): SiteResult => new SiteResult(
                    identifier: $site->getIdentifier(),
                    rootPageId: $site->getRootPageId(),
                    base: (string) $site->getBase(),
                    languages: array_values(array_map(
                        static fn(SiteLanguage $language): SiteLanguageResult => new SiteLanguageResult(
                            languageId: $language->getLanguageId(),
                            title: $language->getTitle(),
                            locale: (string) $language->getLocale(),
                            flagIdentifier: $language->getFlagIdentifier(),
                            enabled: $language->isEnabled(),
                            hreflang: $language->getHreflang(),
                        ),
                        $site->getAllLanguages(),
                    )),
                ),
                $sites,
            ));

            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('site_configuration resource failed', ['exception' => $e]);

            throw new ResourceReadException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
