<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Table\Handler;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Helper\JsonObjectParser;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use MarekSkopal\MsMcpServer\Tool\Table\TableToolConfig;

/**
 * The shared body of the two create handlers, which differ only in their signature.
 *
 * Always called from inside {@see AbstractTableToolHandler::run()} — the JSON decode in particular
 * must stay under the audit wrapper, which is exactly what TMS-31 fixed.
 *
 * @internal
 */
final class RecordCreation
{
    /** @param int|null $sysLanguageUid the language to write, or null for a table without translations */
    public static function run(
        DataHandlerService $dataHandlerService,
        TableToolConfig $config,
        string $fields,
        int $pid,
        ?int $sysLanguageUid,
    ): RecordCreatedResult|ErrorResult {
        $data = JsonObjectParser::parse($fields, 'fields');

        $filteredData = array_intersect_key($data, array_flip($config->writableFields));

        $languageField = $config->languageField;
        if ($languageField !== null) {
            $filteredData[$languageField] = $sysLanguageUid ?? 0;
            unset($data[$languageField]);
        }

        $ignoredFields = array_map('strval', array_values(array_diff(array_keys($data), array_keys($filteredData))));

        if ($filteredData === []) {
            return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
        }

        $uid = $dataHandlerService->createRecord($config->tableName, $pid, $filteredData);

        return new RecordCreatedResult($uid, $ignoredFields);
    }
}
