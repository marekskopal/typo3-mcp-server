<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class ContentCreateTool
{
    public function __construct(
        private DataHandlerService $dataHandlerService,
        private TcaSchemaService $tcaSchemaService,
        private LoggerInterface $logger,
    ) {
    }

    #[McpTool(
        name: 'content_create',
        description: 'Create a new content element on a page. Pass fields as a JSON object string.'
            . ' Use sysLanguageUid to set the language (0 = default, -1 = all languages).',
    )]
    public function execute(int $pid, string $fields, int $sysLanguageUid = 0): RecordCreatedResult|ErrorResult
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $writableFields = $this->tcaSchemaService->getWritableFields('tt_content');
        $filteredData = array_intersect_key($data, array_flip($writableFields));

        $translationConfig = $this->tcaSchemaService->getTranslationConfig('tt_content');
        $languageField = $translationConfig['languageField'];
        if ($languageField !== null) {
            $filteredData[$languageField] = $sysLanguageUid;
            unset($data[$languageField]);
        }

        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

        if ($filteredData === []) {
            return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
        }

        try {
            $uid = $this->dataHandlerService->createRecord('tt_content', $pid, $filteredData);
        } catch (\Throwable $e) {
            $this->logger->error('content_create tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new RecordCreatedResult($uid, $ignoredFields);
    }
}
