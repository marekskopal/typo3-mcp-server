<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class PagesDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(name: 'pages_delete', description: 'Delete a page by its uid.')]
    public function execute(int $uid): RecordDeletedResult
    {
        $this->dataHandlerService->deleteRecord('pages', $uid);

        return new RecordDeletedResult($uid);
    }
}
