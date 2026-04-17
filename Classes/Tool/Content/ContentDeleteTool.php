<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class ContentDeleteTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    /** Delete a content element by its uid. */
    public function execute(int $uid): string
    {
        try {
            $this->dataHandlerService->deleteRecord('tt_content', $uid);
        } catch (\Throwable $e) {
            $this->logger->error('content_delete tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['uid' => $uid, 'deleted' => true], JSON_THROW_ON_ERROR);
    }
}
