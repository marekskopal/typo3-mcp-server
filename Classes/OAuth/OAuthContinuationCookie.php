<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

use const JSON_THROW_ON_ERROR;

/**
 * HMAC-signed cookie carrying the relative URL to return to after the user
 * signs into the TYPO3 backend. Required because TYPO3's `/typo3/login` only
 * honours its own `RouteRedirect` (which lands inside the backend's content
 * iframe), so for a top-level bounce back to our frontend authorize endpoint
 * we need a side-channel.
 */
readonly class OAuthContinuationCookie
{
    public const string COOKIE_NAME = 'mcp_oauth_continuation';

    private const int TTL_SECONDS = 600;

    /**
     * Builds a `Set-Cookie` header value carrying `$relativeUrl`.
     */
    public function issue(string $relativeUrl, bool $secure): string
    {
        $payload = json_encode(
            ['url' => $relativeUrl, 'exp' => time() + self::TTL_SECONDS],
            JSON_THROW_ON_ERROR,
        );
        $encoded = self::base64UrlEncode($payload);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret(), true));

        $flags = sprintf('Max-Age=%d; Path=/; HttpOnly; SameSite=Lax', self::TTL_SECONDS);
        if ($secure) {
            $flags .= '; Secure';
        }

        return sprintf('%s=%s.%s; %s', self::COOKIE_NAME, $encoded, $signature, $flags);
    }

    /**
     * Reads the cookie value, verifies HMAC + expiry, returns the URL or null
     * if the cookie is absent, tampered, expired, or otherwise unsafe.
     */
    public function read(?string $rawCookieValue): ?string
    {
        if ($rawCookieValue === null || $rawCookieValue === '' || !str_contains($rawCookieValue, '.')) {
            return null;
        }

        [$encoded, $signature] = explode('.', $rawCookieValue, 2);
        $expected = self::base64UrlEncode(hash_hmac('sha256', $encoded, $this->secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = self::base64UrlDecode($encoded);
        if ($payload === null) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $exp = $decoded['exp'] ?? null;
        $url = $decoded['url'] ?? null;
        if (!is_int($exp) || $exp < time() || !is_string($url)) {
            return null;
        }

        return $url;
    }

    /**
     * Builds a `Set-Cookie` header value that clears the continuation cookie.
     */
    public function clear(bool $secure): string
    {
        $flags = 'Max-Age=0; Path=/; HttpOnly; SameSite=Lax';
        if ($secure) {
            $flags .= '; Secure';
        }

        return sprintf('%s=; %s', self::COOKIE_NAME, $flags);
    }

    private function secret(): string
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($confVars)) {
            $this->failMissingKey();
        }

        $sys = $confVars['SYS'] ?? null;
        if (!is_array($sys)) {
            $this->failMissingKey();
        }

        $key = $sys['encryptionKey'] ?? null;
        if (!is_string($key) || $key === '') {
            $this->failMissingKey();
        }

        return $key;
    }

    private function failMissingKey(): never
    {
        throw new \RuntimeException('TYPO3_CONF_VARS[SYS][encryptionKey] is required to sign OAuth continuation cookies.', 1717250000);
    }

    private static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $input): ?string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
