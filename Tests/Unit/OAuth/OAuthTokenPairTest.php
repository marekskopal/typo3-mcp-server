<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use MarekSkopal\MsMcpServer\OAuth\OAuthTokenPair;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OAuthTokenPair::class)]
final class OAuthTokenPairTest extends TestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $tokenPair = new OAuthTokenPair(
            accessToken: 'access-123',
            refreshToken: 'refresh-456',
            expiresIn: 3600,
            tokenType: 'CustomType',
        );

        self::assertSame('access-123', $tokenPair->accessToken);
        self::assertSame('refresh-456', $tokenPair->refreshToken);
        self::assertSame(3600, $tokenPair->expiresIn);
        self::assertSame('CustomType', $tokenPair->tokenType);
    }

    public function testDefaultTokenTypeIsBearer(): void
    {
        $tokenPair = new OAuthTokenPair(
            accessToken: 'access-123',
            refreshToken: 'refresh-456',
            expiresIn: 3600,
        );

        self::assertSame('Bearer', $tokenPair->tokenType);
    }
}
