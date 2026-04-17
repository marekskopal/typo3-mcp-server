<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\News;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class NewsUpdateTool
{
    private const string TABLE = 'tx_news_domain_model_news';

    private const array ALLOWED_FIELDS = [
        'title',
        'teaser',
        'bodytext',
        'datetime',
        'hidden',
        'author',
        'author_email',
        'path_segment',
        'type',
        'keywords',
        'description',
    ];

    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    /** Update an existing news record. Pass fields as a JSON object string with field names and their new values. */
    public function execute(int $uid, string $fields): string
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $filteredData = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
        if ($filteredData === []) {
            return json_encode(['error' => 'No valid fields provided'], JSON_THROW_ON_ERROR);
        }

        try {
            $this->dataHandlerService->updateRecord(self::TABLE, $uid, $filteredData);
        } catch (\Throwable $e) {
            $this->logger->error('news_update tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['uid' => $uid, 'updated' => array_keys($filteredData)], JSON_THROW_ON_ERROR);
    }
}
