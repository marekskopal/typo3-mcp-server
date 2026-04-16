<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

final readonly class OAuthTokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public string $tokenType = 'Bearer',
    ) {
    }
}
