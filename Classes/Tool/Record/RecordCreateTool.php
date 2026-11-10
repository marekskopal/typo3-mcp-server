<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Record;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Helper\CreatePosition;
use MarekSkopal\MsMcpServer\Tool\Helper\FieldRejection;
use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Exception\ToolCallException;

/**
 * Creates a record in any TCA table, the counterpart of the record_*_batch tools. Tables that are not
 * enabled extension tables (sys_category, for instance) had no way to get new records (#41).
 * TYPO3's DataHandler decides whether the user may create on that table and page.
 */
readonly class RecordCreateTool
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private RecordService $recordService,
    ) {
    }

    #[McpTool(
        name: 'record_create',
        description: 'Create a record in any table (e.g. sys_category), for tables without their own create tool.'
            . ' Pass fields as a JSON object string; call table_schema for the writable fields.'
            . ' For a translatable table, sysLanguageUid sets the language (0 = default, -1 = all languages); a'
            . ' translation of an existing record can be created directly by also passing its transOrigPointerField'
            . ' (e.g. l10n_parent) in fields, or with record_translate.'
            . CreatePosition::DESCRIPTION,
    )]
    public function execute(
        string $tableName,
        int $pid,
        string $fields,
        int $sysLanguageUid = 0,
        int $afterUid = 0,
        string $position = CreatePosition::BOTTOM,
    ): RecordCreatedResult {
        if (!$this->tcaSchemaService->hasTable($tableName)) {
            throw new ToolCallException('Unknown table "' . $tableName . '".');
        }

        $data = JsonObjectParser::parse($fields, 'fields');
        $filteredData = array_intersect_key($data, array_flip($this->tcaSchemaService->getWritableFields($tableName)));

        $languageField = $this->tcaSchemaService->getTranslationConfig($tableName)['languageField'];
        if ($languageField !== null) {
            $filteredData[$languageField] = $sysLanguageUid;
            unset($data[$languageField]);
        }

        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

        if ($filteredData === []) {
            throw FieldRejection::noValidFields($tableName, $ignoredFields);
        }

        $target = CreatePosition::resolve($this->recordService, $tableName, $pid, $afterUid, $position);
        $uid = $this->dataHandlerService->createRecord($tableName, $target, $filteredData);

        return new RecordCreatedResult($uid, $ignoredFields);
    }
}
