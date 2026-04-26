<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

readonly class PkceVerifier
{
    public function verify(string $codeVerifier, string $codeChallenge): bool
    {
        // RFC 7636: code_verifier must be 43-128 characters, unreserved characters only
        $length = strlen($codeVerifier);
        if ($length < 43 || $length > 128) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9._~-]+$/', $codeVerifier) !== 1) {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }
}
