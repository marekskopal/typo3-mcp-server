<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\File;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Helper\FieldRejection;
use MarekSkopal\MsMcpServer\Tool\Result\FileReferenceAddedResult;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Exception\ToolCallException;
use const JSON_THROW_ON_ERROR;

readonly class FileReferenceAddTool
{
    private const string REFERENCE_TABLE = 'sys_file_reference';

    /** The relation columns the tool sets itself; a `metadata` value for them is dropped, not applied. */
    private const array RELATION_FIELDS = ['uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'sorting_foreign', 'table_local'];

    public function __construct(private DataHandlerService $dataHandlerService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'file_reference_add',
        description: 'Attach uploaded files to a record\'s file/image field. Pass sys_file UIDs from file_upload results.'
            . ' Optional `metadata` (JSON) sets the reference\'s own values (alternative, title, description, link, crop, …)'
            . ' in the same call: either one object applied to every reference, e.g. {"alternative": "Team photo"},'
            . ' or a list with one object per file, matched by sys_file uid, e.g.'
            . ' [{"uid": 2, "alternative": "Hall", "title": "Main hall"}, {"uid": 3, "alternative": "Court"}].'
            . ' With the list form, fileUids may be left empty and the files are taken from the list in its order.'
            . ' A field left out falls back to the file\'s own metadata.',
    )]
    public function execute(string $table, int $uid, string $fieldName, string $fileUids, string $metadata = ''): FileReferenceAddedResult
    {
        $fileFields = $this->tcaSchemaService->getFileFields($table);
        if (!in_array($fieldName, $fileFields, true)) {
            throw new ToolCallException(
                'Field \'' . $fieldName . '\' is not a file field on table \'' . $table
                    . '\'. Available file fields: ' . (implode(', ', $fileFields) ?: '(none)'),
            );
        }

        [$shared, $perFile] = $this->parseMetadata($metadata);

        $parsedUids = $this->parseFileUids($fileUids);
        if ($parsedUids === [] && $perFile !== null) {
            $parsedUids = array_keys($perFile);
        }

        if ($parsedUids === []) {
            throw new ToolCallException('No valid file UIDs provided');
        }

        if ($perFile !== null) {
            $unknown = array_diff(array_keys($perFile), $parsedUids);
            if ($unknown !== []) {
                throw new ToolCallException(
                    'metadata names files that are not in fileUids: ' . implode(', ', $unknown) . '.',
                );
            }
        }

        [$referenceFields, $applied, $ignoredFields] = $this->filterFields($parsedUids, $shared, $perFile ?? []);

        $referenceUids = $this->dataHandlerService->createFileReferences($table, $uid, $fieldName, $parsedUids, $referenceFields);

        return new FileReferenceAddedResult(
            $table,
            $uid,
            $fieldName,
            count($referenceUids),
            $referenceUids,
            $applied,
            $ignoredFields,
        );
    }

    /** @return list<int> */
    private function parseFileUids(string $fileUids): array
    {
        return array_values(array_filter(
            array_map(static fn (string $v): int => (int) trim($v), explode(',', $fileUids)),
            static fn (int $v): bool => $v > 0,
        ));
    }

    /**
     * Decodes `metadata` into the object applied to every reference, or the per-file objects keyed by
     * sys_file uid (in the order given).
     *
     * @return array{array<string, mixed>, array<int, array<string, mixed>>|null}
     */
    private function parseMetadata(string $metadata): array
    {
        if (trim($metadata) === '') {
            return [[], null];
        }

        try {
            $decoded = json_decode($metadata, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ToolCallException(
                'metadata must be a JSON object or a list of objects, but is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new ToolCallException('metadata must be a JSON object or a list of objects, got ' . get_debug_type($decoded) . '.');
        }

        if (!array_is_list($decoded)) {
            return [$this->stringKeyed($decoded, 'metadata'), null];
        }

        $perFile = [];
        foreach ($decoded as $position => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new ToolCallException('metadata[' . $position . '] must be an object with the sys_file "uid" and its values.');
            }

            $fileUid = $entry['uid'] ?? null;
            if (!is_int($fileUid) && !(is_string($fileUid) && ctype_digit($fileUid))) {
                throw new ToolCallException('metadata[' . $position . '] has no sys_file "uid".');
            }

            $fileUid = (int) $fileUid;
            if ($fileUid <= 0 || isset($perFile[$fileUid])) {
                throw new ToolCallException('metadata[' . $position . ']: sys_file uid ' . $fileUid . ' is invalid or listed twice.');
            }

            unset($entry['uid']);
            $perFile[$fileUid] = $this->stringKeyed($entry, 'metadata[' . $position . ']');
        }

        return [[], $perFile];
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    private function stringKeyed(array $data, string $paramName): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new ToolCallException($paramName . ' must be a JSON object of field names and values.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Narrows the requested values to the writable sys_file_reference fields, per file.
     *
     * @param list<int> $fileUids
     * @param array<string, mixed> $shared
     * @param array<int, array<string, mixed>> $perFile
     * @return array{array<int, array<string, mixed>>, list<string>, list<string>} values by fileUids index, applied names, dropped names
     */
    private function filterFields(array $fileUids, array $shared, array $perFile): array
    {
        if ($shared === [] && $perFile === []) {
            return [[], [], []];
        }

        $translationConfig = $this->tcaSchemaService->getTranslationConfig(self::REFERENCE_TABLE);
        $reserved = array_filter([
            'pid',
            ...self::RELATION_FIELDS,
            $translationConfig['languageField'],
            $translationConfig['transOrigPointerField'],
        ]);
        $writable = array_flip(array_diff($this->tcaSchemaService->getWritableFields(self::REFERENCE_TABLE), $reserved));

        $referenceFields = [];
        $applied = [];
        $requested = [];
        foreach ($fileUids as $index => $fileUid) {
            $values = array_merge($shared, $perFile[$fileUid] ?? []);
            $requested = array_merge($requested, array_keys($values));
            $filtered = array_intersect_key($values, $writable);
            if ($filtered === []) {
                continue;
            }

            $referenceFields[$index] = $filtered;
            $applied = array_merge($applied, array_keys($filtered));
        }

        $applied = array_values(array_unique($applied));
        $ignoredFields = array_values(array_diff(array_unique($requested), $applied));

        if ($applied === []) {
            throw FieldRejection::noValidFields(self::REFERENCE_TABLE, $ignoredFields);
        }

        return [$referenceFields, $applied, $ignoredFields];
    }
}
