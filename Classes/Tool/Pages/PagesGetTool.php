<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordNotFoundResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordResult;
use Mcp\Capability\Attribute\McpTool;

readonly class PagesGetTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'pages_get',
        description: 'Get a single page by its uid.' . ' A uid that does not exist is reported as {"found": false}, not an error.',
    )]
    public function execute(int $uid): RecordResult|RecordNotFoundResult
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
            return new RecordNotFoundResult('pages', $uid, 'Page not found');
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
            return new RecordResult(
                $record,
                $this->recordService->findTranslations('pages', $uid, $languageField, $transOrigPointerField),
            );
        }

        return new RecordResult($record);
    }
}
