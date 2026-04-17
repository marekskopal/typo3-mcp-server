<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\News;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class NewsListTool
{
    private const string TABLE = 'tx_news_domain_model_news';

    private const array FIELDS = ['uid', 'pid', 'title', 'teaser', 'datetime', 'hidden', 'categories'];

    public function __construct(private RecordService $recordService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(name: 'news_list', description: 'List news records by page ID with pagination.')]
    public function execute(int $pid, int $limit = 20, int $offset = 0): string
    {
        try {
            $result = $this->recordService->findByPid(self::TABLE, $pid, $limit, $offset, self::FIELDS);
        } catch (\Throwable $e) {
            $this->logger->error('news_list tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
