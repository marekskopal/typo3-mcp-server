<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class ContentListTool
{
    public function __construct(
        private RecordService $recordService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'content_list',
        description: 'List content elements by page ID with pagination. Use sysLanguageUid to filter by language (0 = default, -1 = all).',
    )]
    public function execute(int $pid, int $limit = 20, int $offset = 0, int $sysLanguageUid = -1): string
    {
        $translationConfig = $this->tcaSchemaService->getTranslationConfig('tt_content');
        $fields = $this->tcaSchemaService->getListFields('tt_content');

        $languageField = $translationConfig['languageField'];
        if ($languageField !== null && !in_array($languageField, $fields, true)) {
            $fields[] = $languageField;
        }

        $transOrigPointerField = $translationConfig['transOrigPointerField'];
        if ($transOrigPointerField !== null && !in_array($transOrigPointerField, $fields, true)) {
            $fields[] = $transOrigPointerField;
        }

        try {
            $result = $this->recordService->findByPid(
                'tt_content',
                $pid,
                $limit,
                $offset,
                $fields,
                $sysLanguageUid >= 0 && $languageField !== null ? $sysLanguageUid : null,
                $sysLanguageUid >= 0 ? $languageField : null,
            );
        } catch (\Throwable $e) {
            $this->logger->error('content_list tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
