<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\News;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use const JSON_THROW_ON_ERROR;

final readonly class NewsDeleteTool
{
    private const string TABLE = 'tx_news_domain_model_news';

    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    /** Delete a news record by its uid. */
    public function execute(int $uid): string
    {
        $this->dataHandlerService->deleteRecord(self::TABLE, $uid);

        return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
