<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class SiteLanguagesTool
{
    public function __construct(private SiteLanguageService $siteLanguageService)
    {
    }

    #[McpTool(name: 'site_languages', description: 'List available languages for a site. Pass any page ID belonging to the site.')]
    public function execute(int $pageId): string
    {
        $languages = $this->siteLanguageService->getLanguagesForPage($pageId);

        return json_encode($languages, JSON_THROW_ON_ERROR);
    }
}
