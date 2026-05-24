<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

readonly class McpPathProvider
{
    private const string DEFAULT_BASE_PATH = '/mcp';

    private string $basePath;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $config = $extensionConfiguration->get('ms_mcp_server');
        $raw = is_array($config) ? ($config['mcpBasePath'] ?? null) : null;
        $this->basePath = $this->normalize(is_string($raw) ? $raw : self::DEFAULT_BASE_PATH);
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
     * RFC 8414 §3.1: the well-known URI is inserted between the host and the
     * issuer's path component, so the metadata for issuer `https://host/<path>`
     * lives at `/.well-known/oauth-authorization-server/<path>`.
     */
    public function getMetadataPath(): string
    {
        return '/.well-known/oauth-authorization-server' . $this->basePath;
    }

    /**
     * RFC 9728 §3: same path-insert convention for protected resource metadata —
     * resource `https://host/<path>` is described at
     * `/.well-known/oauth-protected-resource/<path>`.
     */
    public function getResourceMetadataPath(): string
    {
        return '/.well-known/oauth-protected-resource' . $this->basePath;
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
}
