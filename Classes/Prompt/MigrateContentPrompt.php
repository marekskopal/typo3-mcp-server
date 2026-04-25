<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Prompt;

use Mcp\Capability\Attribute\McpPrompt;

readonly class MigrateContentPrompt
{
    /** @return array{user: string} */
    #[McpPrompt(
        name: 'migrate_content',
        description: 'Move all content elements from a source page to a target page, preserving column positions and sorting order.',
    )]
    public function execute(int $sourcePageId, int $targetPageId): array
    {
        return [
            'user' => <<<PROMPT
                Migrate all content from page ID {$sourcePageId} to page ID {$targetPageId}.

                Instructions:
                1. Use pages_get to verify both the source page ({$sourcePageId}) and target page ({$targetPageId}) exist.
                2. Read the backend_layout resource for both pages:
                   - typo3://pages/{$sourcePageId}/backend-layout
                   - typo3://pages/{$targetPageId}/backend-layout
                   Compare available colPos values. Warn if the target layout has fewer columns than the source.
                3. Use content_list on page {$sourcePageId} with sysLanguageUid=-1 to list all content elements (all languages).
                4. Use record_move_batch with tableName="tt_content" to move all content element UIDs to target={$targetPageId} in a single operation.
                5. Use cache_clear with scope "pages" to flush page caches.
                6. Report:
                   - Number of content elements moved.
                   - Any colPos mismatches between source and target layouts.
                   - Verification: use content_list on page {$targetPageId} to confirm all elements arrived.
                PROMPT,
        ];
    }
}
