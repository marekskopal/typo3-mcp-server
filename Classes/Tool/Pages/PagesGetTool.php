<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class PagesGetTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(name: 'pages_get', description: 'Get a single page by its uid.')]
    public function execute(int $uid): string
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig('pages');
        $fields = $this->tcaSchemaService->getReadFields('pages');

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        $record = $this->recordService->findByUid('pages', $uid, $fields);

        if ($record === null) {
            return json_encode(['error' => 'Page not found'], JSON_THROW_ON_ERROR);
        }

        $sysLanguageUid = $record[$languageField ?? ''] ?? -1;
        if (
            $languageField !== null
            && $transOrigPointerField !== null
            && (
                is_int($sysLanguageUid)
                || is_string($sysLanguageUid)
            )
            && (int) $sysLanguageUid === 0
        ) {
            $record['translations'] = $this->recordService->findTranslations('pages', $uid, $languageField, $transOrigPointerField);
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
