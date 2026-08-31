<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Tool\Result\ErrorResult;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Capability\Attribute\McpTool;

readonly class ContentDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private RecordService $recordService)
    {
    }

    #[McpTool(
        name: 'content_delete',
        description: 'Delete a content element by its uid.'
            . ' Set dryRun to true to check what would happen without deleting anything.',
    )]
    public function execute(int $uid, bool $dryRun = false): RecordDeletedResult|ErrorResult
    {
        if ($dryRun) {
            // A preview that skipped this would answer "would delete" for a uid that does not exist
            // or that this user cannot see. findByUid() applies the same read permissions, so the
            // preview agrees with what the real call would do.
            if ($this->recordService->findByUid('tt_content', $uid, ['uid']) === null) {
                return new ErrorResult('Content element not found or not accessible: ' . $uid);
            }

            return new RecordDeletedResult($uid, dryRun: true);
        }

        $this->dataHandlerService->deleteRecord('tt_content', $uid);

        return new RecordDeletedResult($uid);
    }
}
