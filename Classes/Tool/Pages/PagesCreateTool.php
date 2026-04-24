<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCreatedResult;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class PagesCreateTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'pages_create',
        description: 'Create a new page in the TYPO3 page tree. Pass fields as a JSON object string.'
            . ' Use sysLanguageUid to set the language (0 = default, -1 = all languages).',
    )]
    public function execute(int $pid, string $fields, int $sysLanguageUid = 0): RecordCreatedResult|ErrorResult
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $writableFields = $this->tcaSchemaService->getWritableFields('pages');
        $filteredData = array_intersect_key($data, array_flip($writableFields));

        $translationConfig = $this->tcaSchemaService->getTranslationConfig('pages');
        $languageField = $translationConfig['languageField'];
        if ($languageField !== null) {
            $filteredData[$languageField] = $sysLanguageUid;
            unset($data[$languageField]);
        }

        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

        if ($filteredData === []) {
            return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
        }

        $uid = $this->dataHandlerService->createRecord('pages', $pid, $filteredData);

        return new RecordCreatedResult($uid, $ignoredFields);
    }
}
