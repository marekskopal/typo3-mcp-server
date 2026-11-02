<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Helper\FieldRejection;
use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use MarekSkopal\MsMcpServer\Tool\Result\RecordTranslatedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class RecordTranslateTool
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
    ) {
    }

    #[McpTool(
        name: 'record_translate',
        description: 'Create a translation of an existing record. The source record must be in the default language (sys_language_uid = 0). Records with sys_language_uid = -1 (all languages) cannot be translated. Works for pages, tt_content, and any language-aware table. Uses TYPO3 localize command (connected mode).'
            . ' Pass the translated values as `fields`, a JSON object string (e.g. {"title": "Funkce", "hidden": 0}):'
            . ' they are written in the same run, so the new record never carries the "[Translate to …:]" prefix'
            . ' and a page\'s slug is generated from the translated title. Without `fields` the copied texts keep'
            . ' that prefix and pages may come out hidden.',
    )]
    public function execute(string $table, int $uid, int $targetLanguageId, ?string $fields = null): RecordTranslatedResult
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig($table);
        if ($translationConfig['languageField'] === null || $translationConfig['transOrigPointerField'] === null) {
            throw new ToolCallException('Table "' . $table . '" is not language-aware and cannot be translated');
        }

        $languageField = $translationConfig['languageField'];
        $record = $this->recordService->findByUid($table, $uid, ['uid', $languageField]);
        if ($record === null) {
            throw new ToolCallException('Record not found: ' . $table . ':' . $uid);
        }

        /** @var int|string $rawLanguage */
        $rawLanguage = $record[$languageField] ?? -1;
        $currentLanguage = (int) $rawLanguage;
        if ($currentLanguage === -1) {
            throw new ToolCallException(
                'Record ' . $table . ':' . $uid . ' has sys_language_uid = -1 (all languages) and cannot be translated.'
                    . ' Only records in the default language (sys_language_uid = 0) can be translated.',
            );
        }

        if ($currentLanguage !== 0) {
            throw new ToolCallException(
                'Record ' . $table . ':' . $uid . ' is already a translation (sys_language_uid = ' . $currentLanguage . ').'
                    . ' Only default language records (sys_language_uid = 0) can be translated.',
            );
        }

        [$translatedFields, $ignoredFields] = $this->resolveFields($table, $fields, $translationConfig);

        $newUid = $this->dataHandlerService->localizeRecord($table, $uid, $targetLanguageId, $translatedFields);

        return new RecordTranslatedResult($newUid, $table, $targetLanguageId, array_keys($translatedFields), $ignoredFields);
    }

    /**
     * The writable part of `fields`. The language and translation pointer fields are what `localize`
     * itself sets, so they are dropped like any other non-writable name rather than overriding it.
     *
     * @param array{languageField: string, transOrigPointerField: string, translationSource: ?string} $translationConfig
     * @return array{array<string, mixed>, list<string>}
     */
    private function resolveFields(string $table, ?string $fields, array $translationConfig): array
    {
        if ($fields === null || trim($fields) === '') {
            return [[], []];
        }

        $data = JsonObjectParser::parse($fields, 'fields');
        if ($data === []) {
            return [[], []];
        }

        $reserved = array_filter([
            'pid',
            $translationConfig['languageField'],
            $translationConfig['transOrigPointerField'],
            $translationConfig['translationSource'],
        ]);
        $writableFields = array_diff($this->tcaSchemaService->getWritableFields($table), $reserved);
        $filteredData = array_intersect_key($data, array_flip($writableFields));
        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

        if ($filteredData === []) {
            throw FieldRejection::noValidFields($table, $ignoredFields);
        }

        return [$filteredData, $ignoredFields];
    }
}
