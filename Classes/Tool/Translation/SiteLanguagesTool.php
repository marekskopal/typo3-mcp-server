<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use MarekSkopal\MsMcpServer\Tool\Result\SiteLanguagesResult;
use Mcp\Capability\Attribute\McpTool;

readonly class SiteLanguagesTool
{
    public function __construct(private SiteLanguageService $siteLanguageService)
    {
    }

    #[McpTool(name: 'site_languages', description: 'List available languages for a site. Pass any page ID belonging to the site.')]
    public function execute(int $pageId): SiteLanguagesResult
    {
        return new SiteLanguagesResult($this->siteLanguageService->getLanguagesForPage($pageId));
    }
}
