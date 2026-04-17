<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\News;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class NewsGetTool
{
    private const string TABLE = 'tx_news_domain_model_news';

    private const array FIELDS = [
        'uid',
        'pid',
        'title',
        'teaser',
        'bodytext',
        'datetime',
        'hidden',
        'categories',
        'author',
        'author_email',
        'path_segment',
        'type',
        'keywords',
        'description',
    ];

    public function __construct(private RecordService $recordService, private LoggerInterface $logger,)
    {
    }

    /** Get a single news record by its uid. */
    public function execute(int $uid): string
    {
        try {
            $record = $this->recordService->findByUid(self::TABLE, $uid, self::FIELDS);
        } catch (\Throwable $e) {
            $this->logger->error('news_get tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        if ($record === null) {
            return json_encode(['error' => 'News record not found'], JSON_THROW_ON_ERROR);
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
