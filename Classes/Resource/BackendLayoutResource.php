<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Service\BackendLayoutService;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ResourceReadException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class BackendLayoutResource
{
    public function __construct(private BackendLayoutService $backendLayoutService, private LoggerInterface $logger)
    {
    }

    #[McpResourceTemplate(
        uriTemplate: 'typo3://pages/{pageId}/backend-layout',
        name: 'backend_layout',
        description: 'Backend layout for a page including available column positions (colPos) and grid structure.',
        mimeType: 'application/json',
    )]
    public function execute(string $pageId): string
    {
        try {
            $result = $this->backendLayoutService->getBackendLayoutForPage((int) $pageId);
        } catch (\Throwable $e) {
            $this->logger->error('backend_layout resource failed', ['exception' => $e]);

            throw new ResourceReadException($e->getMessage(), (int) $e->getCode(), $e);
        }

        try {
            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('backend_layout resource failed', ['exception' => $e]);

            throw new ResourceReadException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
