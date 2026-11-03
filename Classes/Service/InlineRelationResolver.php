<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * The child records of a record's inline (IRRE) fields as UID lists, e.g. an FAQ question's `answers`.
 *
 * An inline column stores only a count (or nothing, with `foreign_field`), so the read tools left these
 * fields out and the children were only found by listing their folder (#41). They are read through core's
 * {@see RelationHandler}, like the MM fields in {@see MmRelationResolver}, so `foreign_field`,
 * `foreign_match_fields`, the child sorting and the workspace purge behave as in the backend.
 *
 * Fields the user may not read (`exclude` without the grant) and children in tables outside the user's
 * `tables_select` are left out rather than returned as bare UIDs.
 */
readonly class InlineRelationResolver
{
    public function __construct(
        private TcaSchemaService $tcaSchemaService,
        private WorkspaceContextService $workspaceContext,
        private PermissionService $permissionService,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, list<int>> inline field => child uids in sorting order
     */
    public function childUids(string $table, array $row): array
    {
        $inlineFields = $this->tcaSchemaService->getInlineFields($table);
        if ($inlineFields === []) {
            return [];
        }

        $uid = $this->relationUid($row);
        if ($uid === null) {
            return [];
        }

        $children = [];
        foreach ($inlineFields as $field => $config) {
            $foreignTable = $config['foreign_table'] ?? null;
            if (!is_string($foreignTable) || !$this->permissionService->canSelectTable($foreignTable)) {
                continue;
            }

            $children[$field] = $this->readChildren($table, $uid, $foreignTable, $row[$field] ?? '', $config);
        }

        return $children;
    }

    /**
     * @param array<mixed> $config
     * @return list<int>
     */
    private function readChildren(string $table, int $uid, string $foreignTable, mixed $value, array $config): array
    {
        $mmTable = $config['MM'] ?? '';

        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
        $relationHandler->setWorkspaceId($this->workspaceContext->isLive() ? 0 : $this->workspaceContext->getCurrentWorkspaceId());
        $relationHandler->start(
            is_string($value) || is_int($value) ? (string) $value : '',
            $foreignTable,
            is_string($mmTable) ? $mmTable : '',
            $uid,
            $table,
            $config,
        );
        $relationHandler->processDeletePlaceholder();

        $uids = [];
        foreach ($relationHandler->itemArray as $item) {
            $id = $item['id'] ?? null;
            if ((is_int($id) || is_string($id)) && MathUtility::canBeInterpretedAsInteger($id) && (int) $id > 0) {
                $uids[] = (int) $id;
            }
        }

        return $uids;
    }

    /**
     * The uid the children hang off: the version's uid in a non-live workspace (`_ORIG_uid`), as in
     * {@see MmRelationResolver}.
     *
     * @param array<string, mixed> $row
     */
    private function relationUid(array $row): ?int
    {
        foreach (['_ORIG_uid', 'uid'] as $key) {
            $value = $row[$key] ?? null;
            if ((is_int($value) || is_string($value)) && MathUtility::canBeInterpretedAsInteger($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }
}
