<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordMovedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class ContentMoveTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'content_move',
        description: 'Move a content element to a new position.'
            . ' Use a positive target to move to the top of a page (target = page pid).'
            . ' Use a negative target to move after another content element (target = -uid of the element to place after).',
    )]
    public function execute(int $uid, int $target): RecordMovedResult
    {
        $this->dataHandlerService->moveRecord('tt_content', $uid, $target);

        return new RecordMovedResult($uid, $target);
    }
}
