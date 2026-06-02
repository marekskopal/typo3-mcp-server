<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use MarekSkopal\MsMcpServer\OAuth\OAuthContinuationCookie;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthContinuationCookie::class)]
final class OAuthContinuationCookieTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    public function testRoundTripReturnsOriginalUrl(): void
    {
        $cookie = new OAuthContinuationCookie();
        $setCookie = $cookie->issue('/mcp/oauth/authorize?state=abc', secure: true);

        $value = $this->extractCookieValue($setCookie);

        self::assertSame('/mcp/oauth/authorize?state=abc', $cookie->read($value));
    }

    public function testIssueAddsSecureFlagOnlyWhenRequested(): void
    {
        $cookie = new OAuthContinuationCookie();

        $secure = $cookie->issue('/x', secure: true);
        $insecure = $cookie->issue('/x', secure: false);

        self::assertStringContainsString('; Secure', $secure);
        self::assertStringNotContainsString('; Secure', $insecure);
        self::assertStringContainsString('HttpOnly', $secure);
        self::assertStringContainsString('SameSite=Lax', $secure);
    }

    public function testReadRejectsMissingCookie(): void
    {
        $cookie = new OAuthContinuationCookie();

        self::assertNull($cookie->read(null));
        self::assertNull($cookie->read(''));
        self::assertNull($cookie->read('not-a-signed-cookie'));
    }

    public function testReadRejectsTamperedPayload(): void
    {
        $cookie = new OAuthContinuationCookie();
        $value = $this->extractCookieValue($cookie->issue('/mcp/oauth/authorize?a=1', secure: true));

        [$encoded, $signature] = explode('.', $value, 2);
        // flip one byte of the encoded payload
        $tampered = ($encoded[0] === 'A' ? 'B' : 'A') . substr($encoded, 1) . '.' . $signature;

        self::assertNull($cookie->read($tampered));
    }

    public function testReadRejectsTamperedSignature(): void
    {
        $cookie = new OAuthContinuationCookie();
        $value = $this->extractCookieValue($cookie->issue('/mcp/oauth/authorize?a=1', secure: true));

        [$encoded, $signature] = explode('.', $value, 2);
        $tampered = $encoded . '.' . str_repeat('A', strlen($signature));

        self::assertNull($cookie->read($tampered));
    }

    public function testReadRejectsExpiredPayload(): void
    {
        $cookie = new OAuthContinuationCookie();

        // Issue with a key, then manually craft an expired payload signed with the same key.
        $payload = json_encode(['url' => '/mcp/oauth/authorize?a=1', 'exp' => time() - 10]);
        self::assertIsString($payload);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = rtrim(
            strtr(base64_encode(hash_hmac('sha256', $encoded, 'test-encryption-key', true)), '+/', '-_'),
            '=',
        );

        self::assertNull($cookie->read($encoded . '.' . $signature));
    }

    public function testClearReturnsExpiringCookieValue(): void
    {
        $cookie = new OAuthContinuationCookie();

        $clear = $cookie->clear(secure: true);

        self::assertStringContainsString('mcp_oauth_continuation=;', $clear);
        self::assertStringContainsString('Max-Age=0', $clear);
        self::assertStringContainsString('Secure', $clear);
    }

    public function testThrowsWhenEncryptionKeyMissing(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);

        $cookie = new OAuthContinuationCookie();

        $this->expectException(\RuntimeException::class);
        $cookie->issue('/x', secure: true);
    }

    private function extractCookieValue(string $setCookieHeader): string
    {
        self::assertStringStartsWith('mcp_oauth_continuation=', $setCookieHeader);
        $semicolon = strpos($setCookieHeader, ';');
        self::assertIsInt($semicolon);
        $pair = substr($setCookieHeader, 0, $semicolon);

        return substr($pair, strlen('mcp_oauth_continuation='));
    }
}
