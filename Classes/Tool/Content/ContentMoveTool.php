<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class ContentMoveTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger)
    {
    }

    #[McpTool(
        name: 'content_move',
        description: 'Move a content element to a new position.'
            . ' Use a positive target to move to the top of a page (target = page pid).'
            . ' Use a negative target to move after another content element (target = -uid of the element to place after).',
    )]
    public function execute(int $uid, int $target): string
    {
        try {
            $this->dataHandlerService->moveRecord('tt_content', $uid, $target);
        } catch (\Throwable $e) {
            $this->logger->error('content_move tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['uid' => $uid, 'moved' => true, 'target' => $target], JSON_THROW_ON_ERROR);
    }
}
