<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordUpdatedResult;
use Mcp\Capability\Attribute\McpTool;
use const JSON_THROW_ON_ERROR;

readonly class PagesUpdateTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private TcaSchemaService $tcaSchemaService,)
    {
    }

    #[McpTool(
        name: 'pages_update',
        description: 'Update an existing page. Pass fields as a JSON object string with field names and their new values.',
    )]
    public function execute(int $uid, string $fields): RecordUpdatedResult|ErrorResult
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $writableFields = $this->tcaSchemaService->getWritableFields('pages');
        $filteredData = array_intersect_key($data, array_flip($writableFields));
        $ignoredFields = array_values(array_diff(array_keys($data), array_keys($filteredData)));

        if ($filteredData === []) {
            return new ErrorResult('No valid fields provided', ['ignoredFields' => $ignoredFields]);
        }

        $this->dataHandlerService->updateRecord('pages', $uid, $filteredData);

        return new RecordUpdatedResult($uid, array_keys($filteredData), $ignoredFields);
    }
}
