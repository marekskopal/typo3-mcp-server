<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Resource\Result\SystemInfoResult;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ResourceReadException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use const JSON_THROW_ON_ERROR;
use const PHP_OS_FAMILY;
use const PHP_VERSION;

readonly class SystemInfoResource
{
    public function __construct(private Typo3Version $typo3Version, private LoggerInterface $logger)
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
        try {
            $result = new SystemInfoResult(
                typo3Version: $this->typo3Version->getVersion(),
                phpVersion: PHP_VERSION,
                applicationContext: (string) Environment::getContext(),
                os: PHP_OS_FAMILY,
                projectPath: Environment::getProjectPath(),
            );

            return json_encode($result, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('typo3_info resource failed', ['exception' => $e]);

            throw new ResourceReadException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
