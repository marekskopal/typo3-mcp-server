<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\News;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class NewsDeleteTool
{
    private const string TABLE = 'tx_news_domain_model_news';

    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    /** Delete a news record by its uid. */
    public function execute(int $uid): string
    {
        try {
            $this->dataHandlerService->deleteRecord(self::TABLE, $uid);
        } catch (\Throwable $e) {
            $this->logger->error('news_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
