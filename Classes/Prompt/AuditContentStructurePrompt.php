<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Prompt;

use Mcp\Capability\Attribute\McpPrompt;

readonly class AuditContentStructurePrompt
{
    /** @return array{user: string} */
    #[McpPrompt(
        name: 'audit_content_structure',
        description: 'Audit content structure for a page subtree. Finds content elements in non-existent backend layout columns (orphaned after layout changes).',
    )]
    public function execute(int $pageId, int $depth = 3): array
    {
        return [
            'user' => <<<PROMPT
                Audit the content structure for the page subtree starting at page ID {$pageId}.

                Instructions:
                1. Use the pages_tree tool with pid={$pageId} and depth={$depth} to get the page tree structure.
                2. For each page in the tree:
                   a. Read the backend_layout resource at typo3://pages/{pageUid}/backend-layout to get the available column positions (colPos values) for that page.
                   b. Use content_list to list all content elements on that page (sysLanguageUid=-1 to include all languages).
                   c. Compare each content element's colPos value against the backend layout's available columns.
                3. Build a structured report:
                   - For each page: page UID, title, backend layout name, available colPos values.
                   - Orphaned content: list any content elements whose colPos does not exist in the backend layout. Include element UID, CType, header, and current colPos.
                   - Summary: total pages checked, total content elements, number of orphaned elements.
                   - Recommendations: for each orphaned element, suggest moving it to an existing colPos using content_move, or deleting it if it appears unused.
                PROMPT,
        ];
    }
}
