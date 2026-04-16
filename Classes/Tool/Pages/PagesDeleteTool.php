<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use const JSON_THROW_ON_ERROR;

final readonly class PagesDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    /** Delete a page by its uid. */
    public function execute(int $uid): string
    {
        $this->dataHandlerService->deleteRecord('pages', $uid);

        return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
