<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Helper\MoveTarget;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCopiedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class ContentCopyTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'content_copy',
        description: 'Copy a content element. Provide exactly one of: targetPid (copy to the top of that page)'
            . ' or afterUid (copy after that content element, on the same page and column as the sibling).',
    )]
    public function execute(int $uid, int $targetPid = -1, int $afterUid = 0): RecordCopiedResult|ErrorResult
    {
        $target = MoveTarget::resolve($targetPid, $afterUid);
        if ($target instanceof ErrorResult) {
            return $target;
        }

        $newUid = $this->dataHandlerService->copyRecord('tt_content', $uid, $target);

        return new RecordCopiedResult($uid, $newUid);
    }
}
