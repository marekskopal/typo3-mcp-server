<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Resource\Result\SystemInfoResult;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use Mcp\Capability\Attribute\McpResource;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use const JSON_THROW_ON_ERROR;
use const PHP_OS_FAMILY;
use const PHP_VERSION;

readonly class SystemInfoResource
{
    public function __construct(private Typo3Version $typo3Version, private PermissionService $permissionService)
    {
    }

    #[McpResource(
        uri: 'typo3://system/info',
        name: 'typo3_info',
        description: 'TYPO3 system information including version, PHP version, and environment context.',
        mimeType: 'application/json',
    )]
    public function execute(): string
    {
        // The absolute install path and the application context are deployment details a non-admin
        // never sees in the backend — core gates its whole System Information toolbar, which shows
        // exactly these, on isAdmin(). A path is also the classic multiplier for a later file-write
        // or include bug, and nothing in the MCP surface addresses files by filesystem path anyway.
        $isAdmin = $this->permissionService->isAdmin();

        $result = new SystemInfoResult(
            typo3Version: $this->typo3Version->getVersion(),
            phpVersion: PHP_VERSION,
            applicationContext: $isAdmin ? (string) Environment::getContext() : null,
            os: PHP_OS_FAMILY,
            projectPath: $isAdmin ? Environment::getProjectPath() : null,
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }
}
