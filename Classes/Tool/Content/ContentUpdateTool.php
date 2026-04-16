<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use const JSON_THROW_ON_ERROR;

final readonly class ContentUpdateTool
{
    private const array ALLOWED_FIELDS = [
        'CType',
        'header',
        'header_layout',
        'bodytext',
        'hidden',
        'colPos',
        'sys_language_uid',
        'fe_group',
        'subheader',
    ];

    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    /** Update an existing content element. Pass fields as a JSON object string with field names and their new values. */
    public function execute(int $uid, string $fields): string
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($fields, true, 512, JSON_THROW_ON_ERROR);

        $filteredData = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
        if ($filteredData === []) {
            return json_encode(['error' => 'No valid fields provided'], JSON_THROW_ON_ERROR);
        }

        $this->dataHandlerService->updateRecord('tt_content', $uid, $filteredData);

        return json_encode(['uid' => $uid, 'updated' => array_keys($filteredData)], JSON_THROW_ON_ERROR);
    }
}
