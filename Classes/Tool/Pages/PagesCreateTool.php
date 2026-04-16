<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use const JSON_THROW_ON_ERROR;

final readonly class PagesCreateTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    /** Create a new page in the TYPO3 page tree. */
    public function execute(
        string $title,
        int $pid = 0,
        int $doktype = 1,
        bool $hidden = false,
        string $slug = '',
        string $navTitle = '',
        string $subtitle = '',
        string $abstract = '',
    ): string {
        $fields = [
            'title' => $title,
            'doktype' => $doktype,
            'hidden' => $hidden ? 1 : 0,
        ];

        if ($slug !== '') {
            $fields['slug'] = $slug;
        }

        if ($navTitle !== '') {
            $fields['nav_title'] = $navTitle;
        }

        if ($subtitle !== '') {
            $fields['subtitle'] = $subtitle;
        }

        if ($abstract !== '') {
            $fields['abstract'] = $abstract;
        }

        $uid = $this->dataHandlerService->createRecord('pages', $pid, $fields);

        return json_encode(['uid' => $uid, 'title' => $title], JSON_THROW_ON_ERROR);
    }
}
