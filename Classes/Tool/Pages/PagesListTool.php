<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
use const JSON_THROW_ON_ERROR;

final readonly class PagesListTool
{
    private const array FIELDS = ['uid', 'pid', 'title', 'slug', 'doktype', 'hidden', 'sorting'];

    public function __construct(private RecordService $recordService)
    {
    }

    /** List pages by parent page ID with pagination. */
    public function execute(int $pid = 0, int $limit = 20, int $offset = 0): string
    {
        $result = $this->recordService->findByPid('pages', $pid, $limit, $offset, self::FIELDS);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
