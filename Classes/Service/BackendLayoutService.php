<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutCellResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutColumnResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutResult;
use MarekSkopal\MsMcpServer\Resource\Result\BackendLayoutStructureResult;
use TYPO3\CMS\Backend\View\BackendLayout\BackendLayout;
use TYPO3\CMS\Backend\View\BackendLayoutView;

readonly class BackendLayoutService
{
    public function __construct(private BackendLayoutView $backendLayoutView)
    {
    }

    public function getBackendLayoutForPage(int $pageId): BackendLayoutResult
    {
        $backendLayout = $this->backendLayoutView->getBackendLayoutForPage($pageId);

        $columns = $this->buildColumns($backendLayout);
        $structure = $this->buildStructure($backendLayout);

        return new BackendLayoutResult(
            identifier: $backendLayout->getIdentifier(),
            title: $backendLayout->getTitle(),
            description: $backendLayout->getDescription(),
            columns: $columns,
            structure: $structure,
        );
    }

    /** @return list<BackendLayoutColumnResult> */
    private function buildColumns(BackendLayout $backendLayout): array
    {
        $columns = [];
        /** @var array<int|string, string> $usedColumns */
        $usedColumns = $backendLayout->getUsedColumns();
        foreach ($usedColumns as $colPos => $name) {
            $columns[] = new BackendLayoutColumnResult(colPos: (int) $colPos, name: $name);
        }

        return $columns;
    }

    private function buildStructure(BackendLayout $backendLayout): BackendLayoutStructureResult
    {
        $rowsConfig = $this->extractRowsConfig($backendLayout->getStructure());

        $rows = [];
        ksort($rowsConfig);
        foreach ($rowsConfig as $rowConfig) {
            if (!is_array($rowConfig)) {
                continue;
            }

            $columnsConfig = is_array($rowConfig['columns.'] ?? null) ? $rowConfig['columns.'] : [];
            ksort($columnsConfig);

            $cells = [];
            foreach ($columnsConfig as $columnConfig) {
                if (!is_array($columnConfig)) {
                    continue;
                }

                $rawColPos = $columnConfig['colPos'] ?? null;
                $rawName = $columnConfig['name'] ?? null;
                $rawColspan = $columnConfig['colspan'] ?? null;
                $rawRowspan = $columnConfig['rowspan'] ?? null;

                $cells[] = new BackendLayoutCellResult(
                    colPos: is_numeric($rawColPos) ? (int) $rawColPos : null,
                    name: is_string($rawName) ? $rawName : null,
                    colspan: is_numeric($rawColspan) ? (int) $rawColspan : 1,
                    rowspan: is_numeric($rawRowspan) ? (int) $rawRowspan : 1,
                );
            }

            $rows[] = $cells;
        }

        return new BackendLayoutStructureResult(
            colCount: $backendLayout->getColCount(),
            rowCount: $backendLayout->getRowCount(),
            rows: $rows,
        );
    }

    /**
     * @param array<mixed> $structure
     * @return array<string, mixed>
     */
    private function extractRowsConfig(array $structure): array
    {
        $config = $structure['__config'] ?? [];
        if (!is_array($config)) {
            return [];
        }

        $backendLayoutConfig = $config['backend_layout.'] ?? [];
        if (!is_array($backendLayoutConfig)) {
            return [];
        }

        $rows = $backendLayoutConfig['rows.'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        /** @var array<string, mixed> $typedRows */
        $typedRows = $rows;

        return $typedRows;
    }
}
