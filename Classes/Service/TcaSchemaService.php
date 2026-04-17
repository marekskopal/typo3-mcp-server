<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

final readonly class TcaSchemaService
{
    /** TCA types that store simple scalar values readable/writable via DataHandler. */
    private const array VALUE_TYPES = [
        'input',
        'text',
        'number',
        'datetime',
        'email',
        'link',
        'color',
        'slug',
        'check',
        'radio',
        'json',
        'uuid',
        'country',
    ];

    /** TCA types that may store simple values depending on configuration (no MM table). */
    private const array CONDITIONAL_TYPES = [
        'select',
        'group',
    ];

    /** @return list<string> Fields suitable for list views (uid, pid, label fields, enablecolumns.disabled). */
    public function getListFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return ['uid', 'pid'];
        }

        $ctrl = $tca['ctrl'] ?? [];
        if (!is_array($ctrl)) {
            return ['uid', 'pid'];
        }

        $fields = ['uid', 'pid'];

        $labelField = $ctrl['label'] ?? null;
        if (is_string($labelField) && $labelField !== '') {
            $fields[] = $labelField;
        }

        $labelAlt = $ctrl['label_alt'] ?? null;
        if (is_string($labelAlt) && $labelAlt !== '') {
            foreach (explode(',', $labelAlt) as $altField) {
                $altField = trim($altField);
                if ($altField !== '') {
                    $fields[] = $altField;
                }
            }
        }

        $enableColumns = $ctrl['enablecolumns'] ?? [];
        if (is_array($enableColumns)) {
            $disabled = $enableColumns['disabled'] ?? null;
            if (is_string($disabled) && $disabled !== '') {
                $fields[] = $disabled;
            }
        }

        return array_values(array_unique($fields));
    }

    /** @return list<string> All fields that can be read (simple value types + uid + pid). */
    public function getReadFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return ['uid', 'pid'];
        }

        $columns = $tca['columns'] ?? [];
        if (!is_array($columns)) {
            return ['uid', 'pid'];
        }

        $systemFields = $this->getSystemFields($tca);
        $fields = ['uid', 'pid'];

        foreach ($columns as $fieldName => $columnConfig) {
            if (!is_string($fieldName) || !is_array($columnConfig)) {
                continue;
            }

            if (in_array($fieldName, $systemFields, true)) {
                continue;
            }

            if ($this->isReadableField($columnConfig)) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /** @return list<string> Fields that can be written (readable fields minus uid, pid, readOnly, system fields). */
    public function getWritableFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return [];
        }

        $columns = $tca['columns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }

        $systemFields = $this->getSystemFields($tca);
        $fields = [];

        foreach ($columns as $fieldName => $columnConfig) {
            if (!is_string($fieldName) || !is_array($columnConfig)) {
                continue;
            }

            if (in_array($fieldName, $systemFields, true)) {
                continue;
            }

            if ($this->isWritableField($columnConfig)) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /** @return list<string> Field names that are file reference fields (TCA type 'file' or inline with sys_file_reference). */
    public function getFileFields(string $tableName): array
    {
        $tca = $this->getTca($tableName);
        if ($tca === null) {
            return [];
        }

        $columns = $tca['columns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }

        $fields = [];

        foreach ($columns as $fieldName => $columnConfig) {
            if (!is_string($fieldName) || !is_array($columnConfig)) {
                continue;
            }

            if ($this->isFileField($columnConfig)) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /** @param array<mixed> $columnConfig */
    private function isFileField(array $columnConfig): bool
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $type = $config['type'] ?? null;
        if (!is_string($type)) {
            return false;
        }

        if ($type === 'file') {
            return true;
        }

        if ($type === 'inline') {
            $foreignTable = $config['foreign_table'] ?? null;

            return $foreignTable === 'sys_file_reference';
        }

        return false;
    }

    /** @param array<mixed> $columnConfig */
    private function isReadableField(array $columnConfig): bool
    {
        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $type = $config['type'] ?? null;
        if (!is_string($type)) {
            return false;
        }

        if (in_array($type, self::VALUE_TYPES, true)) {
            return true;
        }

        if (in_array($type, self::CONDITIONAL_TYPES, true)) {
            return !$this->hasMMTable($config);
        }

        return false;
    }

    /** @param array<mixed> $columnConfig */
    private function isWritableField(array $columnConfig): bool
    {
        if (!$this->isReadableField($columnConfig)) {
            return false;
        }

        $config = $columnConfig['config'] ?? [];
        if (!is_array($config)) {
            return false;
        }

        $readOnly = $config['readOnly'] ?? false;

        return $readOnly !== true;
    }

    /** @param array<mixed> $config */
    private function hasMMTable(array $config): bool
    {
        $mm = $config['MM'] ?? null;

        return is_string($mm) && $mm !== '';
    }

    /**
     * Returns system/internal field names that should be excluded from tool fields.
     *
     * @param array<mixed> $tca
     * @return list<string>
     */
    private function getSystemFields(array $tca): array
    {
        $ctrl = $tca['ctrl'] ?? [];
        if (!is_array($ctrl)) {
            return [];
        }

        $systemFields = [];

        $ctrlStringFields = [
            'tstamp',
            'crdate',
            'delete',
            'sortby',
            'languageField',
            'transOrigPointerField',
            'translationSource',
            'origUid',
            'descriptionColumn',
        ];

        foreach ($ctrlStringFields as $ctrlKey) {
            $value = $ctrl[$ctrlKey] ?? null;
            if (is_string($value) && $value !== '') {
                $systemFields[] = $value;
            }
        }

        $enableColumns = $ctrl['enablecolumns'] ?? [];
        if (is_array($enableColumns)) {
            foreach ($enableColumns as $value) {
                if (is_string($value) && $value !== '') {
                    $systemFields[] = $value;
                }
            }
        }

        // l10n_diffsource is always a system field
        $systemFields[] = 'l10n_diffsource';
        $systemFields[] = 'l10n_source';
        $systemFields[] = 't3ver_label';

        return array_values(array_unique($systemFields));
    }

    /** @return array<mixed>|null */
    private function getTca(string $tableName): ?array
    {
        $tca = $GLOBALS['TCA'] ?? [];
        if (!is_array($tca)) {
            return null;
        }

        $tableConfig = $tca[$tableName] ?? null;

        return is_array($tableConfig) ? $tableConfig : null;
    }
}
