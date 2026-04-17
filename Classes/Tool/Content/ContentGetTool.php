<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class ContentGetTool
{
    private const array FIELDS = [
        'uid',
        'pid',
        'CType',
        'header',
        'header_layout',
        'bodytext',
        'hidden',
        'sorting',
        'colPos',
        'sys_language_uid',
        'fe_group',
        'subheader',
        'image',
        'media',
    ];

    public function __construct(private RecordService $recordService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(name: 'content_get', description: 'Get a single content element by its uid.')]
    public function execute(int $uid): string
    {
        try {
            $record = $this->recordService->findByUid('tt_content', $uid, self::FIELDS);
        } catch (\Throwable $e) {
            $this->logger->error('content_get tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        if ($record === null) {
            return json_encode(['error' => 'Content element not found'], JSON_THROW_ON_ERROR);
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
