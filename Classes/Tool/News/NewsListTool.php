<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\News;

use MarekSkopal\MsMcpServer\Service\RecordService;
use const JSON_THROW_ON_ERROR;

final readonly class NewsListTool
{
    private const string TABLE = 'tx_news_domain_model_news';

    private const array FIELDS = ['uid', 'pid', 'title', 'teaser', 'datetime', 'hidden', 'categories'];

    public function __construct(private RecordService $recordService)
    {
    }

    /** List news records by page ID with pagination. */
    public function execute(int $pid, int $limit = 20, int $offset = 0): string
    {
        $result = $this->recordService->findByPid(self::TABLE, $pid, $limit, $offset, self::FIELDS);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
