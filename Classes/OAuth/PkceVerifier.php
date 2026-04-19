<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

readonly class PkceVerifier
{
    public function verify(string $codeVerifier, string $codeChallenge): bool
    {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }
}
