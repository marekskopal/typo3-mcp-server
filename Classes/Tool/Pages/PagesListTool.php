<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class PagesListTool
{
    private const array FIELDS = ['uid', 'pid', 'title', 'slug', 'doktype', 'hidden', 'sorting'];

    public function __construct(private RecordService $recordService, private LoggerInterface $logger,)
    {
    }

    /** List pages by parent page ID with pagination. */
    public function execute(int $pid = 0, int $limit = 20, int $offset = 0): string
    {
        try {
            $result = $this->recordService->findByPid('pages', $pid, $limit, $offset, self::FIELDS);
        } catch (\Throwable $e) {
            $this->logger->error('pages_list tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
