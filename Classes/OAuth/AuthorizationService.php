<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class AuthorizationService
{
    private const string TABLE = 'tx_msmcpserver_oauth_authorization';

    private const int DEFAULT_ACCESS_TOKEN_LIFETIME = 3600;

    private const int DEFAULT_REFRESH_TOKEN_LIFETIME = 2592000;

    private const int DEFAULT_REFRESH_TOKEN_MAX_LIFETIME = 7776000;

    private const int DEFAULT_CODE_LIFETIME = 60;

    private int $accessTokenLifetime;

    private int $refreshTokenLifetime;

    private int $refreshTokenMaxLifetime;

    private int $codeLifetime;

    public function __construct(
        private ConnectionPool $connectionPool,
        private PkceVerifier $pkceVerifier,
        private ClientRepository $clientRepository,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $config = $extensionConfiguration->get('ms_mcp_server');
        $accessTokenLifetime = is_array($config) ? ($config['accessTokenLifetime'] ?? null) : null;
        $refreshTokenLifetime = is_array($config) ? ($config['refreshTokenLifetime'] ?? null) : null;
        $refreshTokenMaxLifetime = is_array($config) ? ($config['refreshTokenMaxLifetime'] ?? null) : null;
        $codeLt = is_array($config) ? ($config['codeLifetime'] ?? null) : null;
        $this->accessTokenLifetime = is_numeric($accessTokenLifetime) ? (int) $accessTokenLifetime : self::DEFAULT_ACCESS_TOKEN_LIFETIME;
        $this->refreshTokenLifetime = is_numeric($refreshTokenLifetime)
            ? (int) $refreshTokenLifetime
            : self::DEFAULT_REFRESH_TOKEN_LIFETIME;
        $this->refreshTokenMaxLifetime = is_numeric($refreshTokenMaxLifetime)
            ? (int) $refreshTokenMaxLifetime
            : self::DEFAULT_REFRESH_TOKEN_MAX_LIFETIME;
        $this->codeLifetime = is_numeric($codeLt) ? (int) $codeLt : self::DEFAULT_CODE_LIFETIME;
    }

    public function createAuthorizationCode(
        string $clientId,
        int $beUserUid,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $redirectUri,
    ): string {
        $client = $this->clientRepository->findByClientId($clientId);
        if ($client === null) {
            throw new \RuntimeException('Unknown client', 1712100001);
        }

        $code = bin2hex(random_bytes(32));
        $codeHash = hash('sha256', $code);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'client_id' => $clientId,
            'be_user' => $beUserUid,
            'authorization_code_hash' => $codeHash,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'redirect_uri' => $redirectUri,
            'code_expires' => time() + $this->codeLifetime,
        ]);

        return $code;
    }

    public function exchangeCode(string $code, string $codeVerifier, string $clientId, string $redirectUri,): OAuthTokenPair
    {
        $codeHash = hash('sha256', $code);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array{uid: int|string, client_id: string, be_user: int|string, code_challenge: string, code_challenge_method: string, redirect_uri: string, code_expires: int|string, revoked: int|string}|false $row */
        $row = $queryBuilder
            ->select('uid', 'client_id', 'be_user', 'code_challenge', 'code_challenge_method', 'redirect_uri', 'code_expires', 'revoked')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('authorization_code_hash', $queryBuilder->createNamedParameter($codeHash)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new \RuntimeException('Invalid authorization code', 1712100010);
        }

        if ((int) $row['revoked'] === 1) {
            throw new \RuntimeException('Authorization code has been revoked', 1712100011);
        }

        if ((int) $row['code_expires'] < time()) {
            throw new \RuntimeException('Authorization code has expired', 1712100012);
        }

        if ($row['client_id'] !== $clientId) {
            throw new \RuntimeException('Client ID mismatch', 1712100013);
        }

        if ($row['redirect_uri'] !== $redirectUri) {
            throw new \RuntimeException('Redirect URI mismatch', 1712100014);
        }

        if (!$this->pkceVerifier->verify($codeVerifier, $row['code_challenge'])) {
            throw new \RuntimeException('PKCE verification failed', 1712100015);
        }

        // Atomically consume the code *before* issuing tokens. The conditional `revoked = 0`
        // guard means only one of two concurrent exchanges can flip the row, so a replay or a
        // race yields zero affected rows and no second token pair is minted (TOCTOU-safe).
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affected = (int) $connection->update(self::TABLE, [
            'authorization_code_hash' => '',
            'revoked' => 1,
        ], ['uid' => (int) $row['uid'], 'revoked' => 0]);

        if ($affected === 0) {
            throw new \RuntimeException('Authorization code has already been used', 1712100016);
        }

        // Start a new token family for this authorization; rotated refresh tokens inherit it and
        // its absolute expiry, so the grant cannot be kept alive indefinitely by rotation.
        return $this->issueTokenPair(
            $clientId,
            (int) $row['be_user'],
            bin2hex(random_bytes(16)),
            time() + $this->refreshTokenMaxLifetime,
        );
    }

    public function refreshToken(string $refreshToken, string $clientId): OAuthTokenPair
    {
        $refreshTokenHash = hash('sha256', $refreshToken);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array{uid: int|string, client_id: string, be_user: int|string, token_family: string, refresh_token_expires: int|string, family_expires: int|string, revoked: int|string}|false $row */
        $row = $queryBuilder
            ->select('uid', 'client_id', 'be_user', 'token_family', 'refresh_token_expires', 'family_expires', 'revoked')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('refresh_token_hash', $queryBuilder->createNamedParameter($refreshTokenHash)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new \RuntimeException('Invalid refresh token', 1712100020);
        }

        if ((int) $row['revoked'] === 1) {
            // The refresh token was already rotated or revoked, yet it's being presented again:
            // treat this as token theft (OAuth 2.1 §6.1) and revoke the whole token family so
            // the descendant tokens issued from it can no longer be used either.
            $this->revokeFamily($row['token_family']);

            throw new \RuntimeException('Refresh token has been revoked', 1712100021);
        }

        if ((int) $row['refresh_token_expires'] < time()) {
            throw new \RuntimeException('Refresh token has expired', 1712100022);
        }

        if ($row['client_id'] !== $clientId) {
            throw new \RuntimeException('Client ID mismatch', 1712100023);
        }

        if ($this->clientRepository->findByClientId($clientId) === null) {
            // The client was deleted or disabled after this grant was issued. Deleting a client
            // already revokes its tokens, but this also covers hidden/disabled clients and any row
            // that slipped through — stop honouring the refresh token and tear down the family.
            $this->revokeFamily($row['token_family']);

            throw new \RuntimeException('Client no longer exists', 1712100024);
        }

        $familyExpires = (int) $row['family_expires'];
        if ($familyExpires > 0 && $familyExpires < time()) {
            // The grant has reached its absolute maximum lifetime. Rotation cannot extend it further;
            // the user must re-authorize. Revoke the family so no descendant token stays usable.
            $this->revokeFamily($row['token_family']);

            throw new \RuntimeException('Refresh grant has reached its maximum lifetime', 1712100025);
        }

        // Atomically rotate: flip the presented token to revoked only if it is still active.
        // The conditional `revoked = 0` guard closes the TOCTOU window between the SELECT above
        // and this UPDATE — two concurrent refreshes with the same token cannot both succeed.
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $affected = (int) $connection->update(self::TABLE, [
            'revoked' => 1,
        ], ['uid' => (int) $row['uid'], 'revoked' => 0]);

        if ($affected === 0) {
            // Lost the race: another request already rotated this exact token. Per OAuth 2.1 §6.1
            // treat concurrent reuse the same as replay of a rotated token and revoke the family.
            $this->revokeFamily($row['token_family']);

            throw new \RuntimeException('Refresh token has been revoked', 1712100021);
        }

        return $this->issueTokenPair($clientId, (int) $row['be_user'], $row['token_family'], $familyExpires);
    }

    /** Revoke every authorization belonging to a backend user (e.g. after a password change). */
    public function revokeByBackendUser(int $beUserUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['revoked' => 1], ['be_user' => $beUserUid]);
    }

    /** Revoke every active token in a family. No-op for legacy rows with an empty family. */
    private function revokeFamily(string $tokenFamily): void
    {
        if ($tokenFamily === '') {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['revoked' => 1], ['token_family' => $tokenFamily]);
    }

    public function validateAccessToken(string $accessToken): int
    {
        $accessTokenHash = hash('sha256', $accessToken);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array{be_user: int|string, access_token_expires: int|string, revoked: int|string}|false $row */
        $row = $queryBuilder
            ->select('be_user', 'access_token_expires', 'revoked')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('access_token_hash', $queryBuilder->createNamedParameter($accessTokenHash)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new \RuntimeException('Invalid access token', 1712100030);
        }

        if ((int) $row['revoked'] === 1) {
            throw new \RuntimeException('Access token has been revoked', 1712100031);
        }

        if ((int) $row['access_token_expires'] < time()) {
            throw new \RuntimeException('Access token has expired', 1712100032);
        }

        return (int) $row['be_user'];
    }

    public function revokeToken(string $token): void
    {
        $tokenHash = hash('sha256', $token);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array{uid: int|string}|false $row */
        $row = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq(
                        'access_token_hash',
                        $queryBuilder->createNamedParameter($tokenHash),
                    ),
                    $queryBuilder->expr()->eq(
                        'refresh_token_hash',
                        $queryBuilder->createNamedParameter($tokenHash),
                    ),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'revoked' => 1,
        ], ['uid' => (int) $row['uid']]);
    }

    private function issueTokenPair(string $clientId, int $beUserUid, string $tokenFamily, int $familyExpires): OAuthTokenPair
    {
        $accessToken = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'client_id' => $clientId,
            'be_user' => $beUserUid,
            'access_token_hash' => hash('sha256', $accessToken),
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'token_family' => $tokenFamily,
            'access_token_expires' => time() + $this->accessTokenLifetime,
            'refresh_token_expires' => time() + $this->refreshTokenLifetime,
            'family_expires' => $familyExpires,
        ]);

        return new OAuthTokenPair(accessToken: $accessToken, refreshToken: $refreshToken, expiresIn: $this->accessTokenLifetime);
    }
}
