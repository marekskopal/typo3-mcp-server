<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordNotFoundResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordResult;
use Mcp\Capability\Attribute\McpTool;

readonly class ContentGetTool
{
    public function __construct(private RecordService $recordService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'content_get',
        description: 'Get a single content element by its uid.' . ' A uid that does not exist is reported as {"found": false}, not an error.',
    )]
    public function execute(int $uid): RecordResult|RecordNotFoundResult
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig('tt_content');
        $fields = $this->tcaSchemaService->getReadFields('tt_content');

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        $record = $this->recordService->findByUid('tt_content', $uid, $fields);

        if ($record === null) {
            return new RecordNotFoundResult('tt_content', $uid, 'Content element not found');
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
                $this->recordService->findTranslations('tt_content', $uid, $languageField, $transOrigPointerField),
            );
        }

        return new RecordResult($record);
    }
}
