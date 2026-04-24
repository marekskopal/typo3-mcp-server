<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Service\BackendLayoutService;
use Mcp\Capability\Attribute\McpResourceTemplate;
use const JSON_THROW_ON_ERROR;

readonly class BackendLayoutResource
{
    public function __construct(private BackendLayoutService $backendLayoutService)
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
        $result = $this->backendLayoutService->getBackendLayoutForPage((int) $pageId);

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
