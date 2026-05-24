<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(McpPathProvider::class)]
final class McpPathProviderTest extends TestCase
{
    public function testDefaultsToMcpWhenConfigMissing(): void
    {
        $provider = $this->makeProvider(null);

        self::assertSame('/mcp', $provider->getBasePath());
        self::assertSame('/mcp/oauth/authorize', $provider->getAuthorizePath());
        self::assertSame('/mcp/oauth/token', $provider->getTokenPath());
        self::assertSame('/mcp/oauth/register', $provider->getRegisterPath());
        self::assertSame('/mcp/oauth/revoke', $provider->getRevokePath());
        self::assertSame('/mcp/oauth', $provider->getOAuthCookiePath());
        self::assertSame('/.well-known/oauth-authorization-server/mcp', $provider->getMetadataPath());
        self::assertSame('/.well-known/oauth-protected-resource/mcp', $provider->getResourceMetadataPath());
    }

    public function testCustomBasePathDerivesOAuthEndpoints(): void
    {
        $provider = $this->makeProvider(['mcpBasePath' => '/typo3-mcp']);

        self::assertSame('/typo3-mcp', $provider->getBasePath());
        self::assertSame('/typo3-mcp/oauth/authorize', $provider->getAuthorizePath());
        self::assertSame('/typo3-mcp/oauth/token', $provider->getTokenPath());
        self::assertSame('/typo3-mcp/oauth/register', $provider->getRegisterPath());
        self::assertSame('/typo3-mcp/oauth/revoke', $provider->getRevokePath());
        self::assertSame('/typo3-mcp/oauth', $provider->getOAuthCookiePath());
        self::assertSame('/.well-known/oauth-authorization-server/typo3-mcp', $provider->getMetadataPath());
        self::assertSame('/.well-known/oauth-protected-resource/typo3-mcp', $provider->getResourceMetadataPath());
    }

    public function testNestedBasePathUsesPathInsertConvention(): void
    {
        $provider = $this->makeProvider(['mcpBasePath' => '/some/dir/mcp']);

        self::assertSame('/some/dir/mcp', $provider->getBasePath());
        self::assertSame('/.well-known/oauth-authorization-server/some/dir/mcp', $provider->getMetadataPath());
        self::assertSame('/.well-known/oauth-protected-resource/some/dir/mcp', $provider->getResourceMetadataPath());
        self::assertSame('/some/dir/mcp/oauth/authorize', $provider->getAuthorizePath());
        self::assertSame('/some/dir/mcp/oauth', $provider->getOAuthCookiePath());
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function normalizationCases(): iterable
    {
        yield 'missing leading slash' => ['mcp', '/mcp'];
        yield 'trailing slash stripped' => ['/mcp/', '/mcp'];
        yield 'whitespace trimmed' => ["  /custom  ", '/custom'];
        yield 'empty falls back to default' => ['', '/mcp'];
        yield 'root slash falls back to default' => ['/', '/mcp'];
        yield 'whitespace inside rejected' => ['/bad path', '/mcp'];
        yield 'query char rejected' => ['/bad?path', '/mcp'];
        yield 'fragment char rejected' => ['/bad#path', '/mcp'];
        yield 'nested with trailing slash' => ['/some/dir/mcp/', '/some/dir/mcp'];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalizesConfiguredPath(string $input, string $expected): void
    {
        $provider = $this->makeProvider(['mcpBasePath' => $input]);

        self::assertSame($expected, $provider->getBasePath());
    }

    public function testNonStringConfigFallsBackToDefault(): void
    {
        $provider = $this->makeProvider(['mcpBasePath' => 42]);

        self::assertSame('/mcp', $provider->getBasePath());
    }

    /** @param array<string, mixed>|null $config */
    private function makeProvider(?array $config): McpPathProvider
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($config);

        return new McpPathProvider($extensionConfiguration);
    }
}
