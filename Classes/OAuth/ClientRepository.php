<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use const JSON_THROW_ON_ERROR;

final readonly class ClientRepository
{
    private const string TABLE = 'tx_msmcpserver_oauth_client';

    public function __construct(private ConnectionPool $connectionPool)
    {
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

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => json_encode($redirectUris, JSON_THROW_ON_ERROR),
            'be_user' => 0,
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

        // Allow localhost with any port per OAuth 2.1
        if (in_array($allowedHost, ['localhost', '127.0.0.1', '::1'], true)
            && $allowedHost === $requestedHost
            && ($allowedParsed['scheme'] ?? '') === ($requestedParsed['scheme'] ?? '')
            && ($allowedParsed['path'] ?? '/') === ($requestedParsed['path'] ?? '/')
        ) {
            return true;
        }

        return $allowedUri === $requestedUri;
    }
}
