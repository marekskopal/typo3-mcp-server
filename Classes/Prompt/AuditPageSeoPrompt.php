<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Prompt;

use Mcp\Capability\Attribute\McpPrompt;

readonly class AuditPageSeoPrompt
{
    /** @return array{user: string} */
    #[McpPrompt(
        name: 'audit_page_seo',
        description: 'Audit SEO metadata for a page and its content, then report findings and suggest fixes.',
    )]
    public function execute(int $pageId): array
    {
        return [
            'user' => <<<PROMPT
                Perform an SEO audit of page ID {$pageId}.

                Instructions:
                1. Use the table_schema tool for "pages" to identify SEO-related fields (look for fields like title, description, og_title, og_description, og_image, canonical_link, no_index, no_follow, twitter_title, twitter_description, twitter_image, twitter_card, seo_title, sitemap_changefreq, sitemap_priority).
                2. Use the pages_get tool to fetch the page record and check each SEO field.
                3. Use the content_list tool to list all content elements on page {$pageId}.
                4. For each content element, use the content_get tool to check:
                   - Whether header fields are filled (empty headings are bad for SEO)
                   - Whether image content elements have alt text set via file references
                5. Report findings in a structured format:
                   - Page-level SEO: list each SEO field with its current value and whether it meets best practices (e.g., title length 50-60 chars, description 150-160 chars)
                   - Content-level issues: missing headings, empty alt text, etc.
                   - Recommendations: specific suggestions for improvement with field names and suggested values
                PROMPT,
        ];
    }
}
