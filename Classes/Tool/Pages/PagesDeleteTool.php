<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Pages;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Tool\Result\RecordDeletedResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;

readonly class PagesDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    #[McpTool(name: 'pages_delete', description: 'Delete a page by its uid.')]
    public function execute(int $uid): RecordDeletedResult
    {
        try {
            $this->dataHandlerService->deleteRecord('pages', $uid);
        } catch (\Throwable $e) {
            $this->logger->error('pages_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return new RecordDeletedResult($uid);
    }
}
