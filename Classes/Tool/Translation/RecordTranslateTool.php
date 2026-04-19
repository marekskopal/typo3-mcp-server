<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Translation;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class RecordTranslateTool
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'record_translate',
        description: 'Create a translation of an existing record. Works for pages, tt_content, and any language-aware table. Uses TYPO3 localize command (connected mode). Use the corresponding update tool to set translated field values afterwards.',
    )]
    public function execute(string $table, int $uid, int $targetLanguageId): string
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig($table);
        if ($translationConfig['languageField'] === null || $translationConfig['transOrigPointerField'] === null) {
            return json_encode(
                ['error' => 'Table "' . $table . '" is not language-aware and cannot be translated'],
                JSON_THROW_ON_ERROR,
            );
        }

        try {
            $newUid = $this->dataHandlerService->localizeRecord($table, $uid, $targetLanguageId);
        } catch (\Throwable $e) {
            $this->logger->error('record_translate tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode([
            'uid' => $newUid,
            'table' => $table,
            'targetLanguageId' => $targetLanguageId,
        ], JSON_THROW_ON_ERROR);
    }
}
