<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Middleware;

use MarekSkopal\MsMcpServer\Middleware\OAuthMiddleware;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\AuthorizeParamsValidator;
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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(OAuthMiddleware::class)]
final class OAuthMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $capturedBodies = [];

    /** @var array<string, string> */
    private array $capturedHeaders = [];

    private int $capturedStatusCode = 0;

    protected function setUp(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(null);
        $beUser->method('getUserName')->willReturn(null);
        $GLOBALS['BE_USER'] = $beUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

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

    public function testAuthorizeGetUnauthenticatedRedirectsToBackendLogin(): void
    {
        $request = $this->createRequest('/mcp/oauth/authorize', 'GET');
        $request->method('getQueryParams')->willReturn([
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
            'state' => 'opaque-state',
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test Client',
            'redirect_uris' => ['https://client.example/cb'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $handler);

        self::assertSame(302, $this->capturedStatusCode);
        $location = $this->capturedHeaders['Location'] ?? '';
        self::assertStringStartsWith('/typo3/login?', $location);
        self::assertStringContainsString('redirect=msmcpserver_oauth_bridge', $location);
        self::assertStringContainsString('redirectParams%5Bclient_id%5D=client-abc', $location);
        self::assertStringContainsString('redirectParams%5Bstate%5D=opaque-state', $location);
    }

    public function testAuthorizeGetAuthenticatedRendersConsentForm(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'GET');
        $request->method('getQueryParams')->willReturn([
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
            'state' => 'opaque-state',
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test Client',
            'redirect_uris' => ['https://client.example/cb'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('Authorize MCP Access', $body);
        self::assertStringContainsString('editor', $body);
        self::assertStringContainsString('Test Client', $body);
        self::assertStringContainsString('Authorize Access', $body);
        self::assertStringNotContainsString('<input type="password"', $body);
        self::assertStringNotContainsString('name="username"', $body);
    }

    public function testAuthorizePostWithoutBackendSessionReturns401(): void
    {
        $request = $this->createRequest('/mcp/oauth/authorize', 'POST');
        $csrf = bin2hex(random_bytes(16));
        $request->method('getCookieParams')->willReturn(['mcp_csrf' => $csrf]);
        $request->method('getParsedBody')->willReturn([
            'csrf_token' => $csrf,
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
            'state' => 'opaque-state',
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::never())->method('createAuthorizationCode');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(
            clientRepository: $clientRepository,
            authorizationService: $authorizationService,
        );
        $middleware->process($request, $handler);

        self::assertSame(401, $this->capturedStatusCode);
        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('login_required', $body);
    }

    public function testAuthorizePostSucceedsForAuthenticatedUser(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'POST');
        $csrf = bin2hex(random_bytes(16));
        $request->method('getCookieParams')->willReturn(['mcp_csrf' => $csrf]);
        $request->method('getParsedBody')->willReturn([
            'csrf_token' => $csrf,
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
            'state' => 'opaque-state',
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService
            ->expects(self::once())
            ->method('createAuthorizationCode')
            ->with('client-abc', 42, 'challenge-value', 'S256', 'https://client.example/cb')
            ->willReturn('auth-code-xyz');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(
            clientRepository: $clientRepository,
            authorizationService: $authorizationService,
        );
        $middleware->process($request, $handler);

        self::assertSame(302, $this->capturedStatusCode);
        $location = $this->capturedHeaders['Location'] ?? '';
        self::assertStringContainsString('https://client.example/cb?', $location);
        self::assertStringContainsString('code=auth-code-xyz', $location);
        self::assertStringContainsString('state=opaque-state', $location);
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
        $clientRepository = $this->createStub(ClientRepository::class);

        return new OAuthMiddleware(
            $this->createStub(AuthorizationService::class),
            $clientRepository,
            new AuthorizeParamsValidator($clientRepository),
            $this->createPathProvider($basePath),
            $this->createStub(RateLimitService::class),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );
    }

    private function createMiddlewareWithCapture(
        ?RateLimitService $rateLimitService = null,
        ?ClientRepository $clientRepository = null,
        ?AuthorizationService $authorizationService = null,
        string $basePath = '/mcp',
    ): OAuthMiddleware {
        $stream = $this->createStub(StreamInterface::class);

        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use ($stream): StreamInterface {
                $this->capturedBodies[] = $content;

                return $stream;
            },
        );

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, $value) use (&$response): ResponseInterface {
                $this->capturedHeaders[$name] = is_string($value) ? $value : implode(', ', (array) $value);

                return $response;
            },
        );
        $response->method('withBody')->willReturn($response);

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturnCallback(
            function (int $statusCode = 200) use ($response): ResponseInterface {
                $this->capturedStatusCode = $statusCode;

                return $response;
            },
        );

        $clientRepositoryResolved = $clientRepository ?? $this->createStub(ClientRepository::class);

        return new OAuthMiddleware(
            $authorizationService ?? $this->createStub(AuthorizationService::class),
            $clientRepositoryResolved,
            new AuthorizeParamsValidator($clientRepositoryResolved),
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
