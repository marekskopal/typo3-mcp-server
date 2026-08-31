<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class WorkspaceContextService
{
    public function getCurrentWorkspaceId(): int
    {
        if (!isset($GLOBALS['BE_USER']) || !$GLOBALS['BE_USER'] instanceof BackendUserAuthentication) {
            return 0;
        }

        return (int) $GLOBALS['BE_USER']->workspace;
    }

    /**
     * True when reads need no workspace overlay.
     *
     * `0` is the live workspace. A *negative* id is core's "no workspace access" sentinel: when a
     * user can reach neither live (no `workspace_perms` bit) nor any custom workspace,
     * `BackendUserAuthentication::setDefaultWorkspace()` parks them at `-99`. That names the
     * absence of a workspace, not one to overlay into — treating it as a workspace made a plain
     * editor take the overlay path and lose the record `total`. The `WorkspaceRestriction` added
     * in applyRestriction() still uses the raw id, so such a user keeps seeing live-shaped rows
     * only; this affects the overlay alone.
     */
    public function isLive(): bool
    {
        return $this->getCurrentWorkspaceId() <= 0;
    }

    public function isTableWorkspaceAware(string $table): bool
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return false;
        }

        $tableConfig = $tca[$table] ?? null;
        if (!is_array($tableConfig)) {
            return false;
        }

        $ctrl = $tableConfig['ctrl'] ?? null;
        if (!is_array($ctrl)) {
            return false;
        }

        return (bool) ($ctrl['versioningWS'] ?? false);
    }

    /**
     * Apply the default restrictions for MCP queries to the QueryBuilder:
     * - DeletedRestriction (no-op for tables without soft-delete capability)
     * - WorkspaceRestriction (only when the table is workspace-aware)
     *
     * Caller is expected to have already called removeAll() (the standard pattern in this codebase).
     */
    public function applyRestriction(QueryBuilder $queryBuilder, string $table): void
    {
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        if (!$this->isTableWorkspaceAware($table)) {
            return;
        }

        $queryBuilder->getRestrictions()->add(
            GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->getCurrentWorkspaceId()),
        );
    }

    /**
     * Return the workspace-overlaid row, or null if the row is hidden in the current workspace
     * (e.g., DELETE_PLACEHOLDER). In live or non-workspace-aware tables, the row is returned unchanged.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function overlay(string $table, array $row): ?array
    {
        if ($this->isLive() || !$this->isTableWorkspaceAware($table)) {
            return $row;
        }

        BackendUtility::workspaceOL($table, $row, $this->getCurrentWorkspaceId());

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $overlaid */
        $overlaid = $row;

        return $overlaid;
    }

    /**
     * Apply BackendUtility::workspaceOL() to a list of rows, dropping rows marked as hidden.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function overlayMany(string $table, array $rows): array
    {
        if ($this->isLive() || !$this->isTableWorkspaceAware($table)) {
            return $rows;
        }

        $overlaid = [];
        foreach ($rows as $row) {
            $result = $this->overlay($table, $row);
            if ($result !== null) {
                $overlaid[] = $result;
            }
        }

        return $overlaid;
    }
}
