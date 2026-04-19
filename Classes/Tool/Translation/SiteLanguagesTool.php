<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class SiteLanguagesTool
{
    public function __construct(private SiteLanguageService $siteLanguageService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(name: 'site_languages', description: 'List available languages for a site. Pass any page ID belonging to the site.')]
    public function execute(int $pageId): string
    {
        try {
            $languages = $this->siteLanguageService->getLanguagesForPage($pageId);
        } catch (\Throwable $e) {
            $this->logger->error('site_languages tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($languages, JSON_THROW_ON_ERROR);
    }
}
