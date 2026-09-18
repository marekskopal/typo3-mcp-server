<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Support;

use const JSON_THROW_ON_ERROR;

/**
 * Serializes a tool's result DTO exactly as the MCP SDK does, so a test can assert on the payload
 * the client actually receives rather than on PHP property names. Tools return objects now, but
 * their wire shape is still the contract.
 */
final class JsonResult
{
    /** @return array<string, mixed> */
    public static function of(object $result): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return list<mixed> */
    public static function listOf(object $result): array
    {
        /** @var list<mixed> $decoded */
        $decoded = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
