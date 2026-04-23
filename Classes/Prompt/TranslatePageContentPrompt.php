<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Prompt;

use MarekSkopal\MsMcpServer\Service\SiteLanguageService;
use Mcp\Capability\Attribute\McpPrompt;

readonly class TranslatePageContentPrompt
{
    public function __construct(private SiteLanguageService $siteLanguageService)
    {
    }

    /** @return array{user: string} */
    #[McpPrompt(
        name: 'translate_page_content',
        description: 'Translate a page and all its content elements to the target language or all available languages.',
    )]
    public function execute(int $pageId, int $targetLanguageId = 0): array
    {
        $languages = $this->siteLanguageService->getLanguagesForPage($pageId);

        $languageList = '';
        foreach ($languages as $lang) {
            $status = $lang['enabled'] ? 'enabled' : 'disabled';
            $languageList .= sprintf("  - ID %d: %s (%s, %s)\n", $lang['languageId'], $lang['title'], $lang['locale'], $status);
        }

        $targetInstruction = $targetLanguageId > 0
            ? sprintf('Translate to language ID %d only.', $targetLanguageId)
            : 'Translate to all enabled non-default languages listed above.';

        return [
            'user' => <<<PROMPT
                Translate page ID {$pageId} and all its content elements.

                Available languages for this site:
                {$languageList}
                {$targetInstruction}

                Instructions:
                1. Use the pages_get tool to fetch the page record and note its title and key fields.
                2. Use the content_list tool to list all content elements on page {$pageId} in the default language (sysLanguageUid=0).
                3. For each target language:
                   a. Use the record_translate tool to translate the page record ("pages" table, page UID {$pageId}).
                   b. Use the record_translate tool to translate each content element ("tt_content" table, element UID).
                   c. After translating, use pages_update and content_update to fill in localized field values (translated title, bodytext, etc.).
                4. Use the cache_clear tool with scope "pages" to flush page caches.
                5. Report a summary of all translations created, including UIDs of the translated records.
                PROMPT,
        ];
    }
}
