<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

readonly class McpPathProvider
{
    private const string DEFAULT_BASE_PATH = '/mcp';

    private string $basePath;

    private string $wellKnownPrefix;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $config = $extensionConfiguration->get('ms_mcp_server');
        $raw = is_array($config) ? ($config['mcpBasePath'] ?? null) : null;
        $this->basePath = $this->normalize(is_string($raw) ? $raw : self::DEFAULT_BASE_PATH);
        $this->wellKnownPrefix = $this->deriveWellKnownPrefix($this->basePath);
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getAuthorizePath(): string
    {
        return $this->basePath . '/oauth/authorize';
    }

    public function getTokenPath(): string
    {
        return $this->basePath . '/oauth/token';
    }

    public function getRegisterPath(): string
    {
        return $this->basePath . '/oauth/register';
    }

    public function getRevokePath(): string
    {
        return $this->basePath . '/oauth/revoke';
    }

    public function getOAuthCookiePath(): string
    {
        return $this->basePath . '/oauth';
    }

    /**
     * Parent directory of the base path, used as a prefix for .well-known endpoints.
     * Empty string when the base path is a single segment (e.g. /mcp).
     */
    public function getWellKnownPrefix(): string
    {
        return $this->wellKnownPrefix;
    }

    public function getMetadataPath(): string
    {
        return $this->wellKnownPrefix . '/.well-known/oauth-authorization-server';
    }

    public function getResourceMetadataPath(): string
    {
        return $this->wellKnownPrefix . '/.well-known/oauth-protected-resource';
    }

    private function normalize(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return self::DEFAULT_BASE_PATH;
        }
        if (!str_starts_with($trimmed, '/')) {
            $trimmed = '/' . $trimmed;
        }
        $trimmed = rtrim($trimmed, '/');
        if (preg_match('/[\s?#]/', $trimmed) === 1) {
            return self::DEFAULT_BASE_PATH;
        }
        return $trimmed;
    }

    private function deriveWellKnownPrefix(string $basePath): string
    {
        $lastSlash = strrpos($basePath, '/');
        if ($lastSlash === false || $lastSlash === 0) {
            return '';
        }
        return substr($basePath, 0, $lastSlash);
    }
}
