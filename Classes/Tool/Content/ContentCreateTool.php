<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use const JSON_THROW_ON_ERROR;

final readonly class ContentCreateTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    /** Create a new content element on a page. */
    public function execute(
        int $pid,
        string $cType = 'text',
        string $header = '',
        string $bodytext = '',
        int $colPos = 0,
        bool $hidden = false,
        int $sysLanguageUid = 0,
    ): string {
        $fields = [
            'CType' => $cType,
            'header' => $header,
            'bodytext' => $bodytext,
            'colPos' => $colPos,
            'hidden' => $hidden ? 1 : 0,
            'sys_language_uid' => $sysLanguageUid,
        ];

        $uid = $this->dataHandlerService->createRecord('tt_content', $pid, $fields);

        return json_encode(['uid' => $uid, 'CType' => $cType, 'header' => $header], JSON_THROW_ON_ERROR);
    }
}
