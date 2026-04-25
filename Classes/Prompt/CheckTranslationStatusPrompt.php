<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Prompt;

use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use Mcp\Capability\Attribute\McpPrompt;

readonly class CheckTranslationStatusPrompt
{
    public function __construct(private SiteLanguageService $siteLanguageService)
    {
    }

    /** @return array{user: string} */
    #[McpPrompt(
        name: 'check_translation_status',
        description: 'Check translation coverage for a page subtree. Reports which pages and content elements are missing translations per language.',
    )]
    public function execute(int $pageId, int $depth = 3): array
    {
        $languages = $this->siteLanguageService->getLanguagesForPage($pageId);

        $languageList = '';
        foreach ($languages as $lang) {
            if ($lang['languageId'] === 0) {
                continue;
            }
            $status = $lang['enabled'] ? 'enabled' : 'disabled';
            $languageList .= sprintf("  - ID %d: %s (%s, %s)\n", $lang['languageId'], $lang['title'], $lang['locale'], $status);
        }

        return [
            'user' => <<<PROMPT
                Check translation status for the page subtree starting at page ID {$pageId}.

                Non-default languages for this site:
                {$languageList}
                Instructions:
                1. Use the pages_tree tool with pid={$pageId} and depth={$depth} to get the page tree structure.
                2. For each page in the tree:
                   a. Use content_list with sysLanguageUid=0 to count default-language content elements.
                   b. For each non-default language above, use content_list with that sysLanguageUid to count translated content elements.
                3. Build a translation status report with this structure:
                   - For each page: page UID, title, number of default-language elements, and for each language: number of translated elements and coverage percentage.
                   - Summary: total pages, total content elements, per-language coverage percentage.
                   - Gaps: list pages/languages where translation coverage is below 100%.
                4. Focus only on enabled languages unless specifically asked about disabled ones.
                PROMPT,
        ];
    }
}
