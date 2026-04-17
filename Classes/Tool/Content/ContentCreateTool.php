<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Content;

use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use Mcp\Exception\ToolCallException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class ContentCreateTool
{
    public function __construct(private DataHandlerService $dataHandlerService, private LoggerInterface $logger,)
    {
    }

    /** Create a new content element on a page. */
    public function execute(
        int $pid,
        string $cType = 'text',
        string $header = '',
        string $bodytext = '',
        int $colPos = 0,
        bool $hidden = false,
        int $sysLanguageUid = 0,
    ): string {
        $fields = [
            'CType' => $cType,
            'header' => $header,
            'bodytext' => $bodytext,
            'colPos' => $colPos,
            'hidden' => $hidden ? 1 : 0,
            'sys_language_uid' => $sysLanguageUid,
        ];

        try {
            $uid = $this->dataHandlerService->createRecord('tt_content', $pid, $fields);
        } catch (\Throwable $e) {
            $this->logger->error('content_create tool failed', ['exception' => $e]);

            throw new ToolCallException($e->getMessage(), (int) $e->getCode(), $e);
        }

        return json_encode(['uid' => $uid, 'CType' => $cType, 'header' => $header], JSON_THROW_ON_ERROR);
    }
}
