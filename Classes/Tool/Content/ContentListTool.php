<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\RecordService;
use const JSON_THROW_ON_ERROR;

final readonly class ContentListTool
{
    private const array FIELDS = ['uid', 'pid', 'CType', 'header', 'bodytext', 'hidden', 'sorting', 'colPos', 'sys_language_uid'];

    public function __construct(private RecordService $recordService)
    {
    }

    /** List content elements by page ID with pagination. */
    public function execute(int $pid, int $limit = 20, int $offset = 0): string
    {
        $result = $this->recordService->findByPid('tt_content', $pid, $limit, $offset, self::FIELDS);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
