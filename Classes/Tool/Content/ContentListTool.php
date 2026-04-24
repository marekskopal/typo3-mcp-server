<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class ContentListTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'content_list',
        description: 'List content elements by page ID with pagination. Use sysLanguageUid to filter by language (0 = default, -1 = all).'
            . ' Use selectFields (comma-separated) to choose which fields to return.',
    )]
    public function execute(int $pid, int $limit = 20, int $offset = 0, int $sysLanguageUid = -1, string $selectFields = ''): string
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig('tt_content');

        if ($selectFields !== '') {
            $requested = array_map('trim', explode(',', $selectFields));
            $readable = $this->tcaSchemaService->getReadFields('tt_content');
            $allowed = array_merge(['uid', 'pid'], $readable);
            $valid = array_values(array_intersect($requested, $allowed));
            $fields = $valid !== []
                ? array_values(array_unique(array_merge(['uid', 'pid'], $valid)))
                : $this->tcaSchemaService->getListFields('tt_content');
        } else {
            $fields = $this->tcaSchemaService->getListFields('tt_content');
        }

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        $result = $this->recordService->findByPid(
            'tt_content',
            $pid,
            $limit,
            $offset,
            $fields,
            $sysLanguageUid >= 0 && $languageField !== null ? $sysLanguageUid : null,
            $sysLanguageUid >= 0 ? $languageField : null,
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
