<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordCopiedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class PagesCopyTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(
        name: 'pages_copy',
        description: 'Copy a page to a new position in the page tree.'
            . ' Use a positive target to copy as a child of that page (target = parent pid).'
            . ' Use a negative target to copy after a specific page (target = -uid of the page to place after).'
            . ' Set includeSubpages to true to copy the entire subtree including all subpages.',
    )]
    public function execute(int $uid, int $target, bool $includeSubpages = false): RecordCopiedResult
    {
        try {
            $newUid = $this->dataHandlerService->copyRecord('pages', $uid, $target, $includeSubpages ? 99 : 0);
        } catch (\Throwable $e) {
            $this->logger->error('pages_copy tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new RecordCopiedResult($uid, $newUid);
    }
}
