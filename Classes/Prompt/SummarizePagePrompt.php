<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Prompt;

use Mcp\Capability\Attribute\McpPrompt;

readonly class SummarizePagePrompt
{
    /** @return array{user: string} */
    #[McpPrompt(
        name: 'summarize_page',
        description: 'Generate a content inventory and summary of a page, including all content elements and translations.',
    )]
    public function execute(int $pageId): array
    {
        return [
            'user' => <<<PROMPT
                Create a content inventory and summary of page ID {$pageId}.

                Instructions:
                1. Use the pages_get tool to fetch the page record. Note the title, slug, doktype, hidden status, and language.
                2. Use the content_list tool to list all content elements on page {$pageId} in the default language.
                3. For each content element, use the content_get tool to get its details — note the CType, header, and whether it has body content.
                4. Use the site_languages tool with page ID {$pageId} to check available languages.
                5. For each non-default language, use the content_list tool with the corresponding sysLanguageUid to check which translations exist.
                6. Present a structured summary:
                   - Page info: title, slug, type, visibility status
                   - Content elements: ordered list with type, header, and brief description of content
                   - Translation status: which languages have translations, which are missing
                   - Statistics: total content elements, word count estimate, number of images
                PROMPT,
        ];
    }
}
