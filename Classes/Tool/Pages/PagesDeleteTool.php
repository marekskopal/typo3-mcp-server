<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class PagesDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private RecordService $recordService)
    {
    }

    #[McpTool(
        name: 'pages_delete',
        description: 'Delete a page by its uid.'
            . ' Set dryRun to true to check what would happen without deleting anything.',
    )]
    public function execute(int $uid, bool $dryRun = false): RecordDeletedResult
    {
        if ($dryRun) {
            // A preview that skipped this would answer "would delete" for a uid that does not exist
            // or that this user cannot see. findByUid() applies the same read permissions, so the
            // preview agrees with what the real call would do.
            if ($this->recordService->findByUid('pages', $uid, ['uid']) === null) {
                throw new ToolCallException('Page not found or not accessible: ' . $uid);
            }

            return new RecordDeletedResult($uid, dryRun: true);
        }

        $this->dataHandlerService->deleteRecord('pages', $uid);

        return new RecordDeletedResult($uid);
    }
}
