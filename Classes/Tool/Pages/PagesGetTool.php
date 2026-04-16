<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\RecordService;
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
        'no_cache',
        'fe_group',
        'layout',
        'backend_layout',
    ];

    public function __construct(private RecordService $recordService)
    {
    }

    /** Get a single page by its uid. */
    public function execute(int $uid): string
    {
        $record = $this->recordService->findByUid('pages', $uid, self::FIELDS);
        if ($record === null) {
            return json_encode(['error' => 'Page not found'], JSON_THROW_ON_ERROR);
        }

        return json_encode($record, JSON_THROW_ON_ERROR);
    }
}
