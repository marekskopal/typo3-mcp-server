<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Helper\MoveTarget;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordMovedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class PagesMoveTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'pages_move',
        description: 'Move a page to a new position in the page tree. Provide exactly one of:'
            . ' targetPid (move as the first child of that parent page) or afterUid (move as a sibling after that page,'
            . ' under the same parent). Subpages move with the page.',
    )]
    public function execute(int $uid, int $targetPid = -1, int $afterUid = 0): RecordMovedResult|ErrorResult
    {
        $target = MoveTarget::resolve($targetPid, $afterUid);
        if ($target instanceof ErrorResult) {
            return $target;
        }

        $this->dataHandlerService->moveRecord('pages', $uid, $target);

        return new RecordMovedResult($uid, $target);
    }
}
