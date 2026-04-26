<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use MarekSkopal\MsMcpServer\OAuth\PkceVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PkceVerifier::class)]
final class PkceVerifierTest extends TestCase
{
    public function testVerifyReturnsTrueForValidChallenge(): void
    {
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $pkceVerifier = new PkceVerifier();

        self::assertTrue($pkceVerifier->verify($codeVerifier, $codeChallenge));
    }

    public function testVerifyReturnsFalseForWrongVerifier(): void
    {
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $wrongVerifier = 'xBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $pkceVerifier = new PkceVerifier();

        self::assertFalse($pkceVerifier->verify($wrongVerifier, $codeChallenge));
    }

    public function testVerifyReturnsFalseForWrongChallenge(): void
    {
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $pkceVerifier = new PkceVerifier();

        self::assertFalse($pkceVerifier->verify($codeVerifier, 'wrong-challenge'));
    }

    public function testVerifyReturnsFalseForTooShortVerifier(): void
    {
        $pkceVerifier = new PkceVerifier();

        self::assertFalse($pkceVerifier->verify('too-short', 'irrelevant'));
    }

    public function testVerifyReturnsFalseForTooLongVerifier(): void
    {
        $pkceVerifier = new PkceVerifier();
        $longVerifier = str_repeat('a', 129);

        self::assertFalse($pkceVerifier->verify($longVerifier, 'irrelevant'));
    }

    public function testVerifyReturnsFalseForInvalidCharacters(): void
    {
        $pkceVerifier = new PkceVerifier();
        // 43 chars but contains invalid character '!'
        $invalidVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOE!Xk';

        self::assertFalse($pkceVerifier->verify($invalidVerifier, 'irrelevant'));
    }

    public function testVerifyAcceptsMinimumLengthVerifier(): void
    {
        $pkceVerifier = new PkceVerifier();
        $minVerifier = str_repeat('a', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $minVerifier, true)), '+/', '-_'), '=');

        self::assertTrue($pkceVerifier->verify($minVerifier, $challenge));
    }

    public function testVerifyAcceptsMaximumLengthVerifier(): void
    {
        $pkceVerifier = new PkceVerifier();
        $maxVerifier = str_repeat('a', 128);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $maxVerifier, true)), '+/', '-_'), '=');

        self::assertTrue($pkceVerifier->verify($maxVerifier, $challenge));
    }
}
