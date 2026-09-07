<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Replaces the value of a many-to-many select/group field with the list of related UIDs.
 *
 * The physical column of such a field only stores the relation *count*, so returning it raw would
 * hand a client `"groups": 2` where it expects `"groups": [20, 21]`. The related UIDs live in the
 * MM table and are read through core's {@see RelationHandler}, so `MM_opposite_field`,
 * `MM_match_fields`, `MM_table_where` and the workspace purge behave exactly as they do in the
 * backend, rather than through hand-written SQL that would drift from core.
 *
 * Only fields that were actually selected are resolved: a list call that does not name an MM
 * field stays a single query. Resolving a page of rows costs one MM query per row per field.
 */
readonly class MmRelationResolver
{
    public function __construct(private TcaSchemaService $tcaSchemaService, private WorkspaceContextService $workspaceContext)
    {
    }

    /**
     * The MM fields among $fields, keyed by name with their TCA config. Empty for a table without
     * MM fields or a selection that does not include one — the cheap path every plain read takes.
     *
     * @param list<string> $fields
     * @return array<string, array<mixed>>
     */
    public function selectedMMFields(string $table, array $fields): array
    {
        $mmFields = $this->tcaSchemaService->getMMFields($table);
        if ($mmFields === []) {
            return [];
        }

        return array_intersect_key($mmFields, array_flip($fields));
    }

    public function isMMField(string $table, string $field): bool
    {
        return $this->tcaSchemaService->isMMField($table, $field);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields the fields that were selected; MM fields outside this list are left alone
     * @return array<string, mixed>
     */
    public function resolve(string $table, array $row, array $fields): array
    {
        $mmFields = $this->selectedMMFields($table, $fields);
        if ($mmFields === []) {
            return $row;
        }

        return $this->resolveRow($table, $row, $mmFields);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $fields
     * @return list<array<string, mixed>>
     */
    public function resolveMany(string $table, array $rows, array $fields): array
    {
        $mmFields = $this->selectedMMFields($table, $fields);
        if ($mmFields === []) {
            return $rows;
        }

        return array_map(fn(array $row): array => $this->resolveRow($table, $row, $mmFields), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<mixed>> $mmFields
     * @return array<string, mixed>
     */
    private function resolveRow(string $table, array $row, array $mmFields): array
    {
        $uid = $this->relationUid($row);
        if ($uid === null) {
            // RecordService always selects uid alongside an MM field; reaching this means a caller
            // bypassed it. Fail loudly rather than return [] and have it read as "no relations".
            throw new \RuntimeException(
                sprintf(
                    'Cannot resolve MM field(s) %s of table "%s": the row carries no uid.',
                    implode(', ', array_keys($mmFields)),
                    $table,
                ),
                1725900003,
            );
        }

        foreach ($mmFields as $fieldName => $config) {
            $row[$fieldName] = $this->readRelation($table, $uid, $config);
        }

        return $row;
    }

    /**
     * The uid whose MM rows hold the relation. In a non-live workspace the overlaid row carries the
     * live uid in `uid` and the version's uid in `_ORIG_uid`; DataHandler writes a version's MM rows
     * under the version's uid, so that is the one to read — the live uid would return the live set.
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

    /**
     * Related UIDs in MM sorting order. A group field allowed several tables returns `table_uid`
     * strings, since a bare integer would be ambiguous there; everything else returns integers.
     *
     * @param array<mixed> $config
     * @return list<int|string>
     */
    private function readRelation(string $table, int $uid, array $config): array
    {
        $type = $config['type'] ?? null;
        $allowedTables = $type === 'group' ? ($config['allowed'] ?? '') : ($config['foreign_table'] ?? '');
        if (!is_string($allowedTables) || $allowedTables === '') {
            return [];
        }

        $mmTable = $config['MM'] ?? '';
        if (!is_string($mmTable) || $mmTable === '') {
            return [];
        }

        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
        $relationHandler->setWorkspaceId($this->workspaceContext->isLive() ? 0 : $this->workspaceContext->getCurrentWorkspaceId());
        $relationHandler->start('', $allowedTables, $mmTable, $uid, $table, $config);
        // Same post-processing as the backend's TcaGroup/TcaSelectItems: drop relations to records
        // that carry a delete placeholder in the current workspace.
        $relationHandler->processDeletePlaceholder();

        $multiTable = $type === 'group' && $this->allowsSeveralTables($allowedTables);

        $uids = [];
        foreach ($relationHandler->itemArray as $item) {
            $id = $item['id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !MathUtility::canBeInterpretedAsInteger($id)) {
                continue;
            }

            $itemTable = $item['table'] ?? null;
            $uids[] = $multiTable && is_string($itemTable) ? $itemTable . '_' . (int) $id : (int) $id;
        }

        return $uids;
    }

    private function allowsSeveralTables(string $allowed): bool
    {
        return trim($allowed) === '*' || count($this->tcaSchemaService->parseAllowedTables($allowed)) > 1;
    }
}
