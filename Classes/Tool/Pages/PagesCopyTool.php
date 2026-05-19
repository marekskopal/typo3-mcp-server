<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Helper\MoveTarget;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCopiedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class PagesCopyTool
{
    public function __construct(private DataHandlerService $dataHandlerService)
    {
    }

    #[McpTool(
        name: 'pages_copy',
        description: 'Copy a page to a new position in the page tree. Provide exactly one of:'
            . ' targetPid (copy as the first child of that parent page) or afterUid (copy as a sibling after that page).'
            . ' Set includeSubpages to true to copy the entire subtree including all subpages.',
    )]
    public function execute(int $uid, int $targetPid = -1, int $afterUid = 0, bool $includeSubpages = false): RecordCopiedResult|ErrorResult
    {
        $target = MoveTarget::resolve($targetPid, $afterUid);
        if ($target instanceof ErrorResult) {
            return $target;
        }

        $newUid = $this->dataHandlerService->copyRecord('pages', $uid, $target, $includeSubpages ? 99 : 0);

        return new RecordCopiedResult($uid, $newUid);
    }
}
