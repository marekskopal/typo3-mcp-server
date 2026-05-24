<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Middleware;

use MarekSkopal\MsMcpServer\Middleware\OAuthMiddleware;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\OAuth\RateLimitService;
use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(OAuthMiddleware::class)]
final class OAuthMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $capturedBodies = [];

    public function testNonOAuthPathPassesThrough(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/some-page');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = $this->createMiddleware();
        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    public function testMetadataEndpointReturnsServerConfig(): void
    {
        $request = $this->createRequest('/.well-known/oauth-authorization-server/mcp', 'GET');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        $decoded = $this->decodeCapturedBody();
        self::assertSame('https://example.com/mcp', $decoded['issuer'] ?? null);
        self::assertSame('https://example.com/mcp/oauth/authorize', $decoded['authorization_endpoint'] ?? null);
        self::assertSame('https://example.com/mcp/oauth/token', $decoded['token_endpoint'] ?? null);
        self::assertSame(['S256'], $decoded['code_challenge_methods_supported'] ?? null);
    }

    public function testResourceMetadataEndpointReturnsResourceConfig(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/.well-known/oauth-protected-resource/mcp');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('example.com');
        $uri->method('getPort')->willReturn(443);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        $decoded = $this->decodeCapturedBody();
        self::assertSame('https://example.com:443/mcp', $decoded['resource'] ?? null);
        self::assertSame(['https://example.com:443/mcp'], $decoded['authorization_servers'] ?? null);
    }

    public function testAuthorizeGetWithMissingResponseTypeReturnsError(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/mcp/oauth/authorize');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('example.com');
        $uri->method('getPort')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getQueryParams')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('invalid_request', $body);
        self::assertStringContainsString('response_type', $body);
    }

    public function testRegisterEndpointRequiresJsonContentType(): void
    {
        $request = $this->createRequest('/mcp/oauth/register', 'POST');
        $request->method('getHeaderLine')->willReturn('text/plain');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('Content-Type must be application', $body);
    }

    public function testRevokeEndpointRequiresTokenParameter(): void
    {
        $request = $this->createRequest('/mcp/oauth/revoke', 'POST');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('token parameter is required', $body);
    }

    public function testTokenEndpointWithUnsupportedGrantType(): void
    {
        $request = $this->createRequest('/mcp/oauth/token', 'POST');
        $request->method('getParsedBody')->willReturn(['grant_type' => 'unsupported']);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('invalid_grant', $body);
    }

    /** @return ServerRequestInterface&\PHPUnit\Framework\MockObject\Stub */
    private function createRequest(string $path, string $method): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('example.com');
        $uri->method('getPort')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);

        return $request;
    }

    public function testRateLimitedRequestReturns429(): void
    {
        $rateLimitService = $this->createStub(RateLimitService::class);
        $rateLimitService->method('check')->willReturn(120);

        $request = $this->createRequest('/mcp/oauth/authorize', 'POST');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(rateLimitService: $rateLimitService);
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('too_many_requests', $body);
    }

    public function testNonRateLimitedOAuthRequestPassesThrough(): void
    {
        $rateLimitService = $this->createStub(RateLimitService::class);
        $rateLimitService->method('check')->willReturn(null);

        $request = $this->createRequest('/mcp/oauth/revoke', 'POST');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createStub(RequestHandlerInterface::class);

        $middleware = $this->createMiddlewareWithCapture(rateLimitService: $rateLimitService);
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('token parameter is required', $body);
    }

    public function testCustomBasePathRoutesOAuthSubpaths(): void
    {
        $request = $this->createRequest('/typo3-mcp/oauth/revoke', 'POST');
        $request->method('getParsedBody')->willReturn([]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(basePath: '/typo3-mcp');
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('token parameter is required', $body);
    }

    public function testCustomBasePathDefaultMcpRequestPassesThrough(): void
    {
        $request = $this->createRequest('/mcp/oauth/revoke', 'POST');

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = $this->createMiddleware(basePath: '/typo3-mcp');
        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    public function testResourceMetadataAdvertisesCustomBasePath(): void
    {
        $request = $this->createRequest('/.well-known/oauth-protected-resource/typo3-mcp', 'GET');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(basePath: '/typo3-mcp');
        $middleware->process($request, $handler);

        $decoded = $this->decodeCapturedBody();
        self::assertSame('https://example.com/typo3-mcp', $decoded['resource'] ?? null);
        self::assertSame(['https://example.com/typo3-mcp'], $decoded['authorization_servers'] ?? null);
    }

    public function testNestedBasePathServesPathInsertMetadata(): void
    {
        $request = $this->createRequest('/.well-known/oauth-authorization-server/some/dir/mcp', 'GET');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(basePath: '/some/dir/mcp');
        $middleware->process($request, $handler);

        $decoded = $this->decodeCapturedBody();
        self::assertSame('https://example.com/some/dir/mcp', $decoded['issuer'] ?? null);
        self::assertSame(
            'https://example.com/some/dir/mcp/oauth/authorize',
            $decoded['authorization_endpoint'] ?? null,
        );
        self::assertSame(
            'https://example.com/some/dir/mcp/oauth/token',
            $decoded['token_endpoint'] ?? null,
        );
    }

    public function testNestedBasePathServesPathInsertResourceMetadata(): void
    {
        $request = $this->createRequest('/.well-known/oauth-protected-resource/some/dir/mcp', 'GET');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(basePath: '/some/dir/mcp');
        $middleware->process($request, $handler);

        $decoded = $this->decodeCapturedBody();
        self::assertSame('https://example.com/some/dir/mcp', $decoded['resource'] ?? null);
        self::assertSame(['https://example.com/some/dir/mcp'], $decoded['authorization_servers'] ?? null);
    }

    public function testLegacyPathAppendWellKnownPassesThrough(): void
    {
        $request = $this->createRequest('/some/dir/.well-known/oauth-authorization-server', 'GET');

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = $this->createMiddleware(basePath: '/some/dir/mcp');
        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    private function createMiddleware(string $basePath = '/mcp'): OAuthMiddleware
    {
        return new OAuthMiddleware(
            $this->createStub(AuthorizationService::class),
            $this->createStub(ClientRepository::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(PasswordHashFactory::class),
            $this->createPathProvider($basePath),
            $this->createStub(RateLimitService::class),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );
    }

    private function createMiddlewareWithCapture(?RateLimitService $rateLimitService = null, string $basePath = '/mcp'): OAuthMiddleware
    {
        $stream = $this->createStub(StreamInterface::class);

        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use ($stream): StreamInterface {
                $this->capturedBodies[] = $content;

                return $stream;
            },
        );

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        return new OAuthMiddleware(
            $this->createStub(AuthorizationService::class),
            $this->createStub(ClientRepository::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(PasswordHashFactory::class),
            $this->createPathProvider($basePath),
            $rateLimitService ?? $this->createStub(RateLimitService::class),
            $responseFactory,
            $streamFactory,
        );
    }

    private function createPathProvider(string $basePath): McpPathProvider
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['mcpBasePath' => $basePath]);

        return new McpPathProvider($extensionConfiguration);
    }

    /** @return array<string, mixed> */
    private function decodeCapturedBody(): array
    {
        $body = $this->capturedBodies[0] ?? '';
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
