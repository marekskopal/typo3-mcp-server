<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use const JSON_THROW_ON_ERROR;

final readonly class ContentDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    /** Delete a content element by its uid. */
    public function execute(int $uid): string
    {
        $this->dataHandlerService->deleteRecord('tt_content', $uid);

        return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
