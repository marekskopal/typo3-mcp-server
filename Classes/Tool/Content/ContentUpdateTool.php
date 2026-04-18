<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class ContentUpdateTool
{
    private const array ALLOWED_FIELDS = [
        'CType',
        'header',
        'header_layout',
        'bodytext',
        'hidden',
        'colPos',
        'sys_language_uid',
        'fe_group',
        'subheader',
        'list_type',
        'pi_flexform',
    ];

    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(
        name: 'content_update',
        description: 'Update an existing content element. Pass fields as a JSON object string with field names and their new values.',
    )]
    public function execute(int $uid, string $fields): string
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $filteredData = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
        if ($filteredData === []) {
            return json_encode(['error' => 'No valid fields provided'], JSON_THROW_ON_ERROR);
        }

        try {
            $this->dataHandlerService->updateRecord('tt_content', $uid, $filteredData);
        } catch (\Throwable $e) {
            $this->logger->error('content_update tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['uid' => $uid, 'updated' => array_keys($filteredData)], JSON_THROW_ON_ERROR);
    }
}
