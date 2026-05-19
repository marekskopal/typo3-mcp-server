<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Helper\MoveTarget;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordMovedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class ContentMoveTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'content_move',
        description: 'Move a content element. Provide exactly one of: targetPid (move to top of that page)'
            . ' or afterUid (place after that content element, on the same page and column as the sibling).',
    )]
    public function execute(int $uid, int $targetPid = -1, int $afterUid = 0): RecordMovedResult|ErrorResult
    {
        $target = MoveTarget::resolve($targetPid, $afterUid);
        if ($target instanceof ErrorResult) {
            return $target;
        }

        $this->dataHandlerService->moveRecord('tt_content', $uid, $target);

        return new RecordMovedResult($uid, $target);
    }
}
