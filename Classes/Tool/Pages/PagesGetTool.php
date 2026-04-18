<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class PagesGetTool
{
    private const array FIELDS = [
        'uid',
        'pid',
        'title',
        'slug',
        'doktype',
        'hidden',
        'sorting',
        'nav_title',
        'subtitle',
        'abstract',
        'description',
        'fe_group',
        'layout',
        'backend_layout',
    ];

    public function __construct(private RecordService $recordService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(name: 'pages_get', description: 'Get a single page by its uid.')]
    public function execute(int $uid): string
    {
        try {
            $record = $this->recordService->findByUid('pages', $uid, self::FIELDS);
        } catch (\Throwable $e) {
            $this->logger->error('pages_get tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        if ($record === null) {
            return json_encode(['error' => 'Page not found'], JSON_THROW_ON_ERROR);
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
