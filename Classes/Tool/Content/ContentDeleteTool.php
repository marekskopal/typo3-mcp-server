<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class ContentDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(name: 'content_delete', description: 'Delete a content element by its uid.')]
    public function execute(int $uid): RecordDeletedResult
    {
        $this->dataHandlerService->deleteRecord('tt_content', $uid);

        return new RecordDeletedResult($uid);
    }
}
