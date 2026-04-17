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

        $pkceVerifier = new PkceVerifier();

        self::assertFalse($pkceVerifier->verify('wrong-verifier', $codeChallenge));
    }

    public function testVerifyReturnsFalseForWrongChallenge(): void
    {
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        $pkceVerifier = new PkceVerifier();

        self::assertFalse($pkceVerifier->verify($codeVerifier, 'wrong-challenge'));
    }
}
