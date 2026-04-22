<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordTranslatedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class RecordTranslateTool
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'record_translate',
        description: 'Create a translation of an existing record. The source record must be in the default language (sys_language_uid = 0). Records with sys_language_uid = -1 (all languages) cannot be translated. Works for pages, tt_content, and any language-aware table. Uses TYPO3 localize command (connected mode). Use the corresponding update tool to set translated field values afterwards.',
    )]
    public function execute(string $table, int $uid, int $targetLanguageId): RecordTranslatedResult|ErrorResult
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig($table);
        if ($translationConfig['languageField'] === null || $translationConfig['transOrigPointerField'] === null) {
            return new ErrorResult('Table "' . $table . '" is not language-aware and cannot be translated');
        }

        $languageField = $translationConfig['languageField'];
        $record = $this->recordService->findByUid($table, $uid, ['uid', $languageField]);
        if ($record === null) {
            return new ErrorResult('Record not found: ' . $table . ':' . $uid);
        }

        /** @var int|string $rawLanguage */
        $rawLanguage = $record[$languageField] ?? -1;
        $currentLanguage = (int) $rawLanguage;
        if ($currentLanguage === -1) {
            return new ErrorResult(
                'Record ' . $table . ':' . $uid . ' has sys_language_uid = -1 (all languages) and cannot be translated.'
                    . ' Only records in the default language (sys_language_uid = 0) can be translated.',
            );
        }

        if ($currentLanguage !== 0) {
            return new ErrorResult(
                'Record ' . $table . ':' . $uid . ' is already a translation (sys_language_uid = ' . $currentLanguage . ').'
                    . ' Only default language records (sys_language_uid = 0) can be translated.',
            );
        }

        try {
            $newUid = $this->dataHandlerService->localizeRecord($table, $uid, $targetLanguageId);
        } catch (\Throwable $e) {
            $this->logger->error('record_translate tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new RecordTranslatedResult($newUid, $table, $targetLanguageId);
    }
}
