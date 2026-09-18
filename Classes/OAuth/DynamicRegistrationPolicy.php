<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Whether unauthenticated RFC 7591 dynamic client registration is offered.
 *
 * Open registration is what lets any MCP client connect without an administrator touching the
 * backend module — and also what lets anyone register a client under any name and any HTTPS
 * redirect URI, then send a backend user an authorize link for it. An installation that
 * provisions its clients in the module gains nothing from leaving the endpoint open, so the
 * `dynamicClientRegistrationEnabled` setting turns it off: `/mcp/oauth/register` answers 403 and
 * the RFC 8414 metadata no longer advertises a `registration_endpoint`.
 */
readonly class DynamicRegistrationPolicy
{
    private bool $enabled;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $config = $extensionConfiguration->get('ms_mcp_server');
        $enabled = is_array($config) ? ($config['dynamicClientRegistrationEnabled'] ?? '1') : '1';
        $this->enabled = (bool) $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
