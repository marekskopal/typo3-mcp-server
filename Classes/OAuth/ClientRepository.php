<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use const JSON_THROW_ON_ERROR;

readonly class ClientRepository
{
    public const int MAX_REDIRECT_URIS = 10;

    private const string TABLE = 'tx_msmcpserver_oauth_client';

    private const int MAX_REDIRECT_URI_LENGTH = 2000;

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * Validate redirect URIs supplied at (unauthenticated) dynamic client registration.
     * Per RFC 8252 a public-client redirect URI must be https, an http loopback address,
     * or a private-use (reverse-domain) scheme — never plain http to a remote host.
     *
     * @param list<string> $redirectUris
     * @return string|null Error description, or null if every URI is acceptable.
     */
    public function validateRedirectUrisForRegistration(array $redirectUris): ?string
    {
        if (count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return 'Too many redirect_uris (maximum ' . self::MAX_REDIRECT_URIS . ')';
        }

        foreach ($redirectUris as $uri) {
            if (strlen($uri) > self::MAX_REDIRECT_URI_LENGTH) {
                return 'redirect_uri exceeds maximum length';
            }

            if (!$this->isRegisterableRedirectUri($uri)) {
                return 'Invalid redirect_uri: ' . $uri;
            }
        }

        return null;
    }

    private function isRegisterableRedirectUri(string $uri): bool
    {
        $parsed = parse_url($uri);
        if ($parsed === false || !isset($parsed['scheme']) || isset($parsed['fragment'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host'] ?? '');

        if ($scheme === 'https') {
            return $host !== '';
        }

        // Plain http is only acceptable for loopback redirect URIs (RFC 8252 §7.3).
        if ($scheme === 'http') {
            return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        }

        // Private-use URI scheme for native apps must be reverse-domain (contain a dot).
        return str_contains($scheme, '.');
    }

    /** @return array{uid: int, client_id: string, client_name: string, redirect_uris: string, be_user: int}|null */
    public function findByClientId(string $clientId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        /** @var array{uid: int, client_id: string, client_name: string, redirect_uris: string, be_user: int}|false $row */
        $row = $queryBuilder
            ->select('uid', 'client_id', 'client_name', 'redirect_uris', 'be_user')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('client_id', $queryBuilder->createNamedParameter($clientId)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    public function validateRedirectUri(string $clientId, string $redirectUri): bool
    {
        $client = $this->findByClientId($clientId);
        if ($client === null) {
            return false;
        }

        /** @var list<string> $allowedUris */
        $allowedUris = json_decode($client['redirect_uris'], true, 2, JSON_THROW_ON_ERROR);

        foreach ($allowedUris as $allowedUri) {
            if ($this->matchesRedirectUri($allowedUri, $redirectUri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $redirectUris
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>}
     */
    public function registerClient(string $clientName, array $redirectUris): array
    {
        $clientId = bin2hex(random_bytes(16));

        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR),
            'be_user' => 0,
            // Marks unauthenticated RFC 7591 registrations so mcp:cleanup can purge them when they
            // stay unused; clients created by an admin in the backend module are never purged.
            'dynamically_registered' => 1,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
        ];
    }

    private function matchesRedirectUri(string $allowedUri, string $requestedUri): bool
    {
        $allowedParsed = parse_url($allowedUri);
        $requestedParsed = parse_url($requestedUri);

        if ($allowedParsed === false || $requestedParsed === false) {
            return false;
        }

        $allowedHost = $allowedParsed['host'] ?? '';
        $requestedHost = $requestedParsed['host'] ?? '';

        // Allow loopback redirect URIs to vary only by port (RFC 8252 §7.3); scheme, host,
        // path, and query must still match exactly.
        if (in_array($allowedHost, ['localhost', '127.0.0.1', '::1'], true)
            && $allowedHost === $requestedHost
            && ($allowedParsed['scheme'] ?? '') === ($requestedParsed['scheme'] ?? '')
            && ($allowedParsed['path'] ?? '/') === ($requestedParsed['path'] ?? '/')
            && ($allowedParsed['query'] ?? '') === ($requestedParsed['query'] ?? '')
        ) {
            return true;
        }

        return $allowedUri === $requestedUri;
    }
}
