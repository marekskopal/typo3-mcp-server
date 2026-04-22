<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class ContentDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(name: 'content_delete', description: 'Delete a content element by its uid.')]
    public function execute(int $uid): RecordDeletedResult
    {
        try {
            $this->dataHandlerService->deleteRecord('tt_content', $uid);
        } catch (\Throwable $e) {
            $this->logger->error('content_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new RecordDeletedResult($uid);
    }
}
