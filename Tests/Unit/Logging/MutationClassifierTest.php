<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Logging;

use MarekSkopal\MsMcpServer\Logging\MutationClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MutationClassifier::class)]
final class MutationClassifierTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function readHandlerProvider(): iterable
    {
        // Attribute-discovered tools report a class short name.
        foreach ([
            'PagesGetTool', 'PagesListTool', 'PagesSearchTool', 'PageTreeTool',
            'ContentGetTool', 'ContentListTool', 'ContentSearchTool',
            'BackendUserGetTool', 'BackendUserListTool', 'BackendGroupGetTool', 'BackendGroupListTool',
            'FileGetInfoTool', 'FileListTool', 'FileSearchTool', 'FileStorageListTool', 'FileReferenceListTool',
            'RecordCountTool', 'RecordSearchTool', 'TableSchemaTool',
            'PermissionCheckPageTool', 'PermissionCheckSummaryTool', 'PermissionCheckTableTool',
            'SiteLanguagesTool',
        ] as $handler) {
            yield $handler => [$handler];
        }

        // Registrar tools report an MCP tool name.
        foreach (['item_list', 'item_get', 'redirect_list', 'redirect_get', 'scheduler_list',
            'workspace_list', 'workspace_get', 'workspace_changes_list'] as $handler) {
            yield $handler => [$handler];
        }
    }

    #[DataProvider('readHandlerProvider')]
    public function testReadsAreNotMutations(string $handler): void
    {
        self::assertFalse(MutationClassifier::isMutation($handler, 'tool'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function writeHandlerProvider(): iterable
    {
        foreach ([
            'PagesCreateTool', 'PagesUpdateTool', 'PagesDeleteTool', 'PagesMoveTool', 'PagesCopyTool',
            'ContentCreateTool', 'ContentUpdateTool', 'ContentDeleteTool', 'ContentMoveTool', 'ContentCopyTool',
            'RecordDeleteBatchTool', 'RecordUpdateBatchTool', 'RecordMoveBatchTool', 'RecordTranslateTool',
            'FileUploadTool', 'FileUploadFromUrlTool', 'FileCopyTool', 'FileDeleteTool', 'FileMoveTool',
            'FileRenameTool', 'FileReferenceAddTool', 'FileReferenceRemoveTool',
            'DirectoryCreateTool', 'DirectoryDeleteTool', 'DirectoryMoveTool', 'DirectoryRenameTool',
            'CacheClearTool',
        ] as $handler) {
            yield $handler => [$handler];
        }

        foreach (['item_create', 'item_update', 'item_delete', 'item_move',
            'item_delete_batch', 'item_update_batch', 'item_move_batch',
            'redirect_create', 'redirect_update', 'redirect_delete', 'scheduler_update', 'scheduler_delete',
            'workspace_switch', 'workspace_publish', 'workspace_discard', 'workspace_stage_set'] as $handler) {
            yield $handler => [$handler];
        }
    }

    #[DataProvider('writeHandlerProvider')]
    public function testWritesAreMutations(string $handler): void
    {
        self::assertTrue(MutationClassifier::isMutation($handler, 'tool'));
    }

    /**
     * The fail-closed property, and the reason the check enumerates reads rather than writes: a tool
     * added later under an unfamiliar name must land in the audit trail, not vanish from it.
     */
    public function testUnrecognisedHandlerIsTreatedAsAMutation(): void
    {
        self::assertTrue(MutationClassifier::isMutation('SomeFutureTool', 'tool'));
        self::assertTrue(MutationClassifier::isMutation('thing_frobnicate', 'tool'));
    }

    public function testResourcesAndPromptsAreNeverMutations(): void
    {
        self::assertFalse(MutationClassifier::isMutation('BackendLayoutResource', 'resource'));
        self::assertFalse(MutationClassifier::isMutation('TranslatePageContentPrompt', 'prompt'));
    }
}
