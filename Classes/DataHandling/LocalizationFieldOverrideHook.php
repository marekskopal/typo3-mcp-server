<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\DataHandling;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Puts translated field values into the record a `localize` command creates, in the same DataHandler run.
 *
 * Core's `localize` copies the source row (prefixing `prefixLangTitle` fields with "[Translate to …:]") and
 * hands it to a nested DataHandler as a NEW record. That nested datamap is where a page's slug is generated
 * from its title, so setting the real title afterwards with an update is too late: the slug was already
 * built from the prefixed title, and changing it then leaves EXT:redirects' redirects behind for a slug that
 * was never public (#37). This hook replaces the values in the NEW record's incoming field array before
 * DataHandler checks and stores it, so the field access checks and slug generation see the translated values.
 *
 * The overrides are registered for one table/source uid/target language and consumed by the first NEW record
 * that matches — the translation itself, not its localized inline children, whose translation pointer
 * names their own source record.
 *
 * Shared (`public: true` in Services.yaml) so the instance DataHandler resolves via `makeInstance()` is the
 * one `DataHandlerService` registers the overrides with.
 */
class LocalizationFieldOverrideHook
{
    /** @var array<string, array{languageField: string, transOrigPointerField: string, fields: array<string, mixed>}> */
    private array $pending = [];

    /** @param array<string, mixed> $fields */
    public function register(
        string $table,
        int $sourceUid,
        int $targetLanguageId,
        string $languageField,
        string $transOrigPointerField,
        array $fields,
    ): void {
        $this->pending[$this->key($table, $sourceUid, $targetLanguageId)] = [
            'languageField' => $languageField,
            'transOrigPointerField' => $transOrigPointerField,
            'fields' => $fields,
        ];
    }

    /**
     * Drops the overrides for the given localization and reports whether they were still pending,
     * i.e. whether no NEW record of that localization reached DataHandler.
     */
    public function release(string $table, int $sourceUid, int $targetLanguageId): bool
    {
        $key = $this->key($table, $sourceUid, $targetLanguageId);
        $wasPending = isset($this->pending[$key]);
        unset($this->pending[$key]);

        return $wasPending;
    }

    // The method name is dictated by TYPO3's DataHandler hook dispatcher, so it cannot be camelCase.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    /** @param array<string, mixed> $incomingFieldArray */
    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        string|int $id,
        DataHandler $dataHandler,
    ): void {
        if ($this->pending === [] || !is_string($id) || !str_starts_with($id, 'NEW')) {
            return;
        }

        foreach ($this->pending as $key => $override) {
            $language = $incomingFieldArray[$override['languageField']] ?? null;
            $sourceUid = $incomingFieldArray[$override['transOrigPointerField']] ?? null;
            if ((!is_int($language) && !is_string($language)) || (!is_int($sourceUid) && !is_string($sourceUid))) {
                continue;
            }

            if ($key !== $this->key($table, (int) $sourceUid, (int) $language)) {
                continue;
            }

            foreach ($override['fields'] as $field => $value) {
                $incomingFieldArray[$field] = $value;
            }

            unset($this->pending[$key]);

            return;
        }
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    private function key(string $table, int $sourceUid, int $targetLanguageId): string
    {
        return $table . ':' . $sourceUid . ':' . $targetLanguageId;
    }
}
