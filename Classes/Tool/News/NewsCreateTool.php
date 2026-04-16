<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\News;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use const JSON_THROW_ON_ERROR;

final readonly class NewsCreateTool
{
    private const string TABLE = 'tx_news_domain_model_news';

    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    /** Create a new news record. */
    public function execute(
        int $pid,
        string $title,
        string $teaser = '',
        string $bodytext = '',
        string $datetime = '',
        bool $hidden = false,
        string $author = '',
        string $authorEmail = '',
        string $pathSegment = '',
    ): string {
        $fields = [
            'title' => $title,
            'teaser' => $teaser,
            'bodytext' => $bodytext,
            'hidden' => $hidden ? 1 : 0,
        ];

        if ($datetime !== '') {
            $fields['datetime'] = strtotime($datetime) ?: 0;
        }

        if ($author !== '') {
            $fields['author'] = $author;
        }

        if ($authorEmail !== '') {
            $fields['author_email'] = $authorEmail;
        }

        if ($pathSegment !== '') {
            $fields['path_segment'] = $pathSegment;
        }

        $uid = $this->dataHandlerService->createRecord(self::TABLE, $pid, $fields);

        return json_encode(['uid' => $uid, 'title' => $title], JSON_THROW_ON_ERROR);
    }
}
