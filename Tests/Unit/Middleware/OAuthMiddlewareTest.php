<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Middleware;

use MarekSkopal\MsMcpServer\Middleware\OAuthMiddleware;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\AuthorizeParamsValidator;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\OAuth\DynamicRegistrationPolicy;
use MarekSkopal\MsMcpServer\OAuth\OAuthContinuationCookie;
use MarekSkopal\MsMcpServer\OAuth\RateLimitService;
use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderManifestInterface;
use TYPO3\CMS\Core\Authentication\Mfa\MfaRequiredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_CONF_VARS']);
        GeneralUtility::purgeInstances();
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
        self::assertSame('https://example.com/mcp/oauth/register', $decoded['registration_endpoint'] ?? null);
    }

    /**
     * With self-registration switched off the metadata must not advertise it, or a client would
     * try, get a 403, and have no hint that it needs a pre-provisioned client_id instead.
     */
    public function testMetadataOmitsRegistrationEndpointWhenDynamicRegistrationDisabled(): void
    {
        $request = $this->createRequest('/.well-known/oauth-authorization-server/mcp', 'GET');

        $middleware = $this->createMiddlewareWithCapture(registrationEnabled: false);
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        $decoded = $this->decodeCapturedBody();
        self::assertArrayNotHasKey('registration_endpoint', $decoded);
        self::assertSame('https://example.com/mcp/oauth/token', $decoded['token_endpoint'] ?? null);
    }

    public function testRegisterEndpointReturns403WhenDynamicRegistrationDisabled(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn(
            json_encode(['client_name' => 'App', 'redirect_uris' => ['https://app/cb']], JSON_THROW_ON_ERROR),
        );

        $request = $this->createRequest('/mcp/oauth/register', 'POST');
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getBody')->willReturn($stream);

        $clientRepository = $this->createMock(ClientRepository::class);
        $clientRepository->expects(self::never())->method('registerClient');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository, registrationEnabled: false);
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(403, $this->capturedStatusCode);
        self::assertSame('access_denied', $this->decodeCapturedBody()['error'] ?? null);
    }

    /**
     * The name is what the consent screen shows, so a bidi override or zero-width padding in it
     * would let a registrant disguise the client. It is normalised before it is stored.
     */
    public function testRegisterEndpointNormalizesClientName(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn(
            json_encode(
                ['client_name' => "\u{202E}Claude \t\n  Desktop\u{200B} ", 'redirect_uris' => ['https://app/cb']],
                JSON_THROW_ON_ERROR,
            ),
        );

        $request = $this->createRequest('/mcp/oauth/register', 'POST');
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getBody')->willReturn($stream);

        $capturedName = null;
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('validateRedirectUrisForRegistration')->willReturn(null);
        $clientRepository->method('registerClient')
            ->willReturnCallback(function (string $name) use (&$capturedName): array {
                $capturedName = $name;

                return ['client_id' => 'c1', 'client_name' => $name, 'redirect_uris' => ['https://app/cb']];
            });

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame('Claude Desktop', $capturedName);
    }

    public function testAuthorizeGetConsentNamesRedirectTargetWithoutWarningForAdminCreatedClient(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'GET');
        $request->method('getQueryParams')->willReturn([
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example:8443/deep/callback?x=1',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Admin Client',
            'redirect_uris' => '["https://client.example:8443/deep/callback?x=1"]',
            'be_user' => 0,
            'dynamically_registered' => 0,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);
        $clientRepository->method('isSelfRegistered')->willReturn(false);

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        $body = $this->capturedBodies[0] ?? '';
        // The displayed target is host[:port] only — the full URI still travels in the hidden field.
        self::assertStringContainsString('The authorization will be sent to <strong>client.example:8443</strong>', $body);
        self::assertStringContainsString('name="redirect_uri" value="https://client.example:8443/deep/callback?x=1"', $body);
        self::assertStringNotContainsString('registered itself', $body);
    }

    /**
     * A self-registered client's name is whatever the registrant typed, so the consent screen has
     * to say the client was never vetted by an administrator.
     */
    public function testAuthorizeGetConsentWarnsForSelfRegisteredClient(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'GET');
        $request->method('getQueryParams')->willReturn([
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'com.example.app:/oauth',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Claude Desktop',
            'redirect_uris' => '["com.example.app:/oauth"]',
            'be_user' => 0,
            'dynamically_registered' => 1,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);
        $clientRepository->method('isSelfRegistered')->willReturn(true);

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('This client registered itself', $body);
        self::assertStringContainsString('not been verified by an administrator', $body);
        self::assertStringContainsString('<strong>com.example.app:/oauth</strong>', $body);
        self::assertStringContainsString('Authorize Access', $body);
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
            'be_user' => 0,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $handler);

        self::assertSame(302, $this->capturedStatusCode);
        $location = $this->capturedHeaders['Location'] ?? '';
        self::assertSame('/typo3/login?login_status=login', $location);

        $setCookie = $this->capturedHeaders['Set-Cookie'] ?? '';
        self::assertStringStartsWith('mcp_oauth_continuation=', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Lax', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);

        // The cookie payload should round-trip to the authorize URL we wanted to resume on
        $cookieValue = $this->extractCookieValue($setCookie);
        $cookie = new OAuthContinuationCookie();
        $url = $cookie->read($cookieValue);
        self::assertIsString($url);
        self::assertStringStartsWith('/mcp/oauth/authorize?', $url);
        self::assertStringContainsString('client_id=client-abc', $url);
        self::assertStringContainsString('state=opaque-state', $url);
    }

    public function testBackendBounceFromTypo3MainRedirectsBackToAuthorize(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $GLOBALS['BE_USER'] = $beUser;

        $cookie = new OAuthContinuationCookie();
        $cookieValue = $this->extractCookieValue(
            $cookie->issue('/mcp/oauth/authorize?client_id=client-abc&state=opaque-state', secure: true),
        );

        $request = $this->createRequest('/typo3/main', 'GET');
        $request->method('getCookieParams')->willReturn([
            OAuthContinuationCookie::COOKIE_NAME => $cookieValue,
        ]);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        self::assertSame(302, $this->capturedStatusCode);
        self::assertSame(
            '/mcp/oauth/authorize?client_id=client-abc&state=opaque-state',
            $this->capturedHeaders['Location'] ?? '',
        );
        $clearCookie = $this->capturedHeaders['Set-Cookie'] ?? '';
        self::assertStringContainsString('mcp_oauth_continuation=;', $clearCookie);
        self::assertStringContainsString('Max-Age=0', $clearCookie);
    }

    public function testBackendBounceIgnoredWhenCookieTampered(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/typo3/main', 'GET');
        $request->method('getCookieParams')->willReturn([
            OAuthContinuationCookie::COOKIE_NAME => 'forged.cookie.value',
        ]);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = $this->createMiddleware();
        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    public function testBackendBounceIgnoredWhenNotAuthenticated(): void
    {
        $cookie = new OAuthContinuationCookie();
        $cookieValue = $this->extractCookieValue(
            $cookie->issue('/mcp/oauth/authorize?client_id=client-abc', secure: true),
        );

        $request = $this->createRequest('/typo3/main', 'GET');
        $request->method('getCookieParams')->willReturn([
            OAuthContinuationCookie::COOKIE_NAME => $cookieValue,
        ]);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = $this->createMiddleware();
        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    public function testBackendBounceIgnoredWhenUrlNotAuthorizeEndpoint(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $GLOBALS['BE_USER'] = $beUser;

        $cookie = new OAuthContinuationCookie();
        // Valid signature but the URL points outside our authorize endpoint
        $cookieValue = $this->extractCookieValue($cookie->issue('https://evil.example/steal', secure: true));

        $request = $this->createRequest('/typo3/main', 'GET');
        $request->method('getCookieParams')->willReturn([
            OAuthContinuationCookie::COOKIE_NAME => $cookieValue,
        ]);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = $this->createMiddleware();
        self::assertSame($expectedResponse, $middleware->process($request, $handler));
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
            'be_user' => 0,
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
        self::assertSame('DENY', $this->capturedHeaders['X-Frame-Options'] ?? null);
        self::assertSame("frame-ancestors 'none'", $this->capturedHeaders['Content-Security-Policy'] ?? null);
        self::assertSame('no-store', $this->capturedHeaders['Cache-Control'] ?? null);
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function csrfCookieSchemeProvider(): iterable
    {
        yield 'https keeps Secure' => ['https', true];
        yield 'http drops Secure' => ['http', false];
    }

    /**
     * A hardcoded `Secure` flag made the browser discard the cookie on a plain-HTTP
     * install, so every consent submission failed CSRF validation.
     */
    #[DataProvider('csrfCookieSchemeProvider')]
    public function testAuthorizeGetSetsSecureOnCsrfCookieOnlyOverHttps(string $scheme, bool $expectSecure): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'GET', $scheme);
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
            'be_user' => 0,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $handler);

        $cookie = $this->capturedHeaders['Set-Cookie'] ?? '';
        self::assertStringStartsWith('mcp_csrf=', $cookie);
        self::assertStringContainsString('HttpOnly; SameSite=Strict', $cookie);
        self::assertStringContainsString('Max-Age=600', $cookie);
        self::assertSame($expectSecure, str_contains($cookie, '; Secure'));
    }

    #[DataProvider('csrfCookieSchemeProvider')]
    public function testAuthorizePostClearsCsrfCookieWithMatchingSecureFlag(string $scheme, bool $expectSecure): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'POST', $scheme);
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
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Test Client',
            'redirect_uris' => '["https://client.example/cb"]',
            'be_user' => 0,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $authorizationService = $this->createStub(AuthorizationService::class);
        $authorizationService->method('createAuthorizationCode')->willReturn('auth-code-xyz');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(
            clientRepository: $clientRepository,
            authorizationService: $authorizationService,
        );
        $middleware->process($request, $handler);

        $cookie = $this->capturedHeaders['Set-Cookie'] ?? '';
        self::assertStringStartsWith('mcp_csrf=;', $cookie);
        self::assertStringContainsString('Max-Age=0', $cookie);
        self::assertSame($expectSecure, str_contains($cookie, '; Secure'));
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
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Test Client',
            'redirect_uris' => '["https://client.example/cb"]',
            'be_user' => 0,
        ]);
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

    public function testAuthorizePostRejectsNonS256Challenge(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'POST');
        $csrf = bin2hex(random_bytes(16));
        $request->method('getCookieParams')->willReturn(['mcp_csrf' => $csrf]);
        $request->method('getParsedBody')->willReturn([
            'csrf_token' => $csrf,
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'plain',
            'state' => 'opaque-state',
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Test Client',
            'redirect_uris' => '["https://client.example/cb"]',
            'be_user' => 0,
        ]);
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

        self::assertSame(400, $this->capturedStatusCode);
        self::assertStringContainsString('code_challenge_method must be', $this->capturedBodies[0] ?? '');
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

    public function testRegisterEndpointRejectsMalformedJson(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn('{ this is not valid json');

        $request = $this->createRequest('/mcp/oauth/register', 'POST');
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getBody')->willReturn($stream);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        self::assertSame(400, $this->capturedStatusCode);
        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('invalid_request', $body);
        self::assertStringContainsString('valid JSON', $body);
    }

    public function testRegisterEndpointTruncatesOverlongClientName(): void
    {
        $longName = str_repeat('a', 300);
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn(
            json_encode(['client_name' => $longName, 'redirect_uris' => ['https://app/cb']], JSON_THROW_ON_ERROR),
        );

        $request = $this->createRequest('/mcp/oauth/register', 'POST');
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getBody')->willReturn($stream);

        $capturedName = null;
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('validateRedirectUrisForRegistration')->willReturn(null);
        $clientRepository->method('registerClient')
            ->willReturnCallback(function (string $name) use (&$capturedName): array {
                $capturedName = $name;

                return ['client_id' => 'c1', 'client_name' => $name, 'redirect_uris' => ['https://app/cb']];
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $handler);

        self::assertSame(255, mb_strlen((string) $capturedName));
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
        // The specific failure reason must not leak to the client.
        self::assertStringNotContainsString('Unsupported grant type', $body);
    }

    /** @return ServerRequestInterface&\PHPUnit\Framework\MockObject\Stub */
    /**
     * The backend module lets an administrator bind a client to one backend user. That binding
     * has to refuse everyone else at the consent screen, not merely be stored.
     */
    public function testAuthorizeGetDeniesClientRestrictedToAnotherUser(): void
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
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Bound Client',
            'redirect_uris' => '["https://client.example/cb"]',
            'be_user' => 7,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);
        $clientRepository->method('restrictsToAnotherUser')->willReturnCallback(
            static fn (array $client, int $beUserUid): bool => (int) $client['be_user'] > 0 && (int) $client['be_user'] !== $beUserUid,
        );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $handler);

        self::assertSame(403, $this->capturedStatusCode);
        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('Access denied', $body);
        self::assertStringContainsString('Bound Client', $body);
        self::assertStringContainsString('restricted to a different TYPO3 backend user', $body);
        self::assertStringContainsString('editor', $body);
        self::assertStringContainsString('error=access_denied', $body);
        self::assertStringNotContainsString('Authorize Access', $body);
        self::assertStringNotContainsString('name="csrf_token"', $body);
        self::assertArrayNotHasKey('Set-Cookie', $this->capturedHeaders);
        self::assertSame('DENY', $this->capturedHeaders['X-Frame-Options'] ?? null);
        self::assertSame('no-store', $this->capturedHeaders['Cache-Control'] ?? null);
    }

    public function testAuthorizeGetRendersConsentWhenClientRestrictedToCurrentUser(): void
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
        ]);

        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Bound Client',
            'redirect_uris' => '["https://client.example/cb"]',
            'be_user' => 42,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);
        $clientRepository->method('restrictsToAnotherUser')->willReturnCallback(
            static fn (array $client, int $beUserUid): bool => (int) $client['be_user'] > 0 && (int) $client['be_user'] !== $beUserUid,
        );

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $clientRepository);
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(200, $this->capturedStatusCode);
        self::assertStringContainsString('Authorize Access', $this->capturedBodies[0] ?? '');
    }

    /**
     * The POST is what mints the code, so the binding is enforced there too — a forged form, or a
     * client re-assigned between GET and POST, must not get a code.
     */
    public function testAuthorizePostDeniesClientRestrictedToAnotherUser(): void
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
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Bound Client',
            'redirect_uris' => '["https://client.example/cb"]',
            'be_user' => 7,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);
        $clientRepository->method('restrictsToAnotherUser')->willReturn(true);

        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::never())->method('createAuthorizationCode');

        $middleware = $this->createMiddlewareWithCapture(
            clientRepository: $clientRepository,
            authorizationService: $authorizationService,
        );
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(403, $this->capturedStatusCode);
        self::assertSame('access_denied', $this->decodeCapturedBody()['error'] ?? null);
        self::assertStringContainsString('mcp_csrf=;', $this->capturedHeaders['Set-Cookie'] ?? '');
    }

    /**
     * Core's backend middleware refuses every module to a user whom the `requireMfa` policy
     * obliges to set MFA up first. The consent screen runs in the frontend stack and has to
     * apply the same gate itself, or such a user could mint a long-lived token from a
     * password-only session.
     */
    public function testAuthorizeGetRedirectsToBackendLoginWhenMfaSetupIsRequired(): void
    {
        $GLOBALS['BE_USER'] = $this->createBackendUserRequiringMfaSetup();

        $request = $this->createRequest('/mcp/oauth/authorize', 'GET');
        $request->method('getQueryParams')->willReturn($this->validAuthorizeParams());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $this->clientRepositoryAcceptingClient());
        $middleware->process($request, $handler);

        self::assertSame(302, $this->capturedStatusCode);
        self::assertSame('/typo3/login?login_status=login', $this->capturedHeaders['Location'] ?? '');
        self::assertStringStartsWith('mcp_oauth_continuation=', $this->capturedHeaders['Set-Cookie'] ?? '');
        self::assertSame([], $this->capturedBodies, 'No consent form may be rendered');
    }

    /** @return iterable<string, array{0: mixed, 1: int|null}> */
    public static function mfaSetupRequirementSatisfiedProvider(): iterable
    {
        yield 'MFA already passed in this session' => [true, null];
        yield 'switch-user mode, which core exempts' => [null, 1];
    }

    #[DataProvider('mfaSetupRequirementSatisfiedProvider')]
    public function testAuthorizeGetRendersConsentWhenMfaSetupRequirementIsSatisfied(
        mixed $mfaSessionFlag,
        ?int $switchUserOriginalUid,
    ): void {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('editor');
        $beUser->method('isMfaSetupRequired')->willReturn(true);
        $beUser->method('getSessionData')->willReturn($mfaSessionFlag);
        $beUser->method('getOriginalUserIdWhenInSwitchUserMode')->willReturn($switchUserOriginalUid);
        $GLOBALS['BE_USER'] = $beUser;

        $request = $this->createRequest('/mcp/oauth/authorize', 'GET');
        $request->method('getQueryParams')->willReturn($this->validAuthorizeParams());

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $this->clientRepositoryAcceptingClient());
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(200, $this->capturedStatusCode);
        self::assertStringContainsString('Authorize Access', $this->capturedBodies[0] ?? '');
    }

    public function testAuthorizePostReturns401WhenMfaSetupIsRequired(): void
    {
        $GLOBALS['BE_USER'] = $this->createBackendUserRequiringMfaSetup();

        $request = $this->createRequest('/mcp/oauth/authorize', 'POST');
        $csrf = bin2hex(random_bytes(16));
        $request->method('getCookieParams')->willReturn(['mcp_csrf' => $csrf]);
        $request->method('getParsedBody')->willReturn(['csrf_token' => $csrf] + $this->validAuthorizeParams());

        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::never())->method('createAuthorizationCode');

        $middleware = $this->createMiddlewareWithCapture(
            clientRepository: $this->clientRepositoryAcceptingClient(),
            authorizationService: $authorizationService,
        );
        $middleware->process($request, $this->createStub(RequestHandlerInterface::class));

        self::assertSame(401, $this->capturedStatusCode);
        self::assertSame('login_required', $this->decodeCapturedBody()['error'] ?? null);
    }

    /**
     * A session whose first factor passed but whose MFA challenge is still open makes `start()`
     * throw `MfaRequiredException`. That used to escape as an uncaught 500; it is a
     * not-authenticated state, so the user is sent through the backend login (which routes them
     * to the MFA form), and the half-authenticated object is not left in `$GLOBALS['BE_USER']`.
     */
    public function testAuthorizeGetRedirectsToBackendLoginWhenSessionStillOwesMfaChallenge(): void
    {
        unset($GLOBALS['BE_USER']);

        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('start')->willThrowException(
            new MfaRequiredException($this->createStub(MfaProviderManifestInterface::class), 1613687097),
        );
        GeneralUtility::addInstance(BackendUserAuthentication::class, $beUser);

        $request = $this->createRequest('/mcp/oauth/authorize', 'GET');
        $request->method('getQueryParams')->willReturn($this->validAuthorizeParams());

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture(clientRepository: $this->clientRepositoryAcceptingClient());
        $middleware->process($request, $handler);

        self::assertSame(302, $this->capturedStatusCode);
        self::assertSame('/typo3/login?login_status=login', $this->capturedHeaders['Location'] ?? '');
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    public function testBackendBounceIgnoredWhenMfaSetupIsRequired(): void
    {
        $GLOBALS['BE_USER'] = $this->createBackendUserRequiringMfaSetup();

        $cookie = new OAuthContinuationCookie();
        $cookieValue = $this->extractCookieValue(
            $cookie->issue('/mcp/oauth/authorize?client_id=client-abc', secure: true),
        );

        $request = $this->createRequest('/typo3/main', 'GET');
        $request->method('getCookieParams')->willReturn([
            OAuthContinuationCookie::COOKIE_NAME => $cookieValue,
        ]);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = $this->createMiddleware();
        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    private function createBackendUserRequiringMfaSetup(): BackendUserAuthentication
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->method('getUserId')->willReturn(42);
        $beUser->method('getUserName')->willReturn('newcomer');
        $beUser->method('isMfaSetupRequired')->willReturn(true);
        $beUser->method('getSessionData')->willReturn(null);
        $beUser->method('getOriginalUserIdWhenInSwitchUserMode')->willReturn(null);

        return $beUser;
    }

    /** @return array<string, string> */
    private function validAuthorizeParams(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
            'state' => 'opaque-state',
        ];
    }

    private function clientRepositoryAcceptingClient(): ClientRepository
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'uid' => 1,
            'client_id' => 'client-abc',
            'client_name' => 'Test Client',
            'redirect_uris' => '["https://client.example/cb"]',
            'be_user' => 0,
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        return $clientRepository;
    }

    private function createRequest(string $path, string $method, string $scheme = 'https'): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getScheme')->willReturn($scheme);
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
            new OAuthContinuationCookie(),
            $this->createPathProvider($basePath),
            $this->createStub(RateLimitService::class),
            $this->registrationPolicy(enabled: true),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
            new NullLogger(),
        );
    }

    private function createMiddlewareWithCapture(
        ?RateLimitService $rateLimitService = null,
        ?ClientRepository $clientRepository = null,
        ?AuthorizationService $authorizationService = null,
        string $basePath = '/mcp',
        bool $registrationEnabled = true,
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
            new OAuthContinuationCookie(),
            $this->createPathProvider($basePath),
            $rateLimitService ?? $this->createStub(RateLimitService::class),
            $this->registrationPolicy($registrationEnabled),
            $responseFactory,
            $streamFactory,
            new NullLogger(),
        );
    }

    private function extractCookieValue(string $setCookieHeader): string
    {
        $semicolon = strpos($setCookieHeader, ';');
        self::assertIsInt($semicolon);
        $pair = substr($setCookieHeader, 0, $semicolon);
        $equals = strpos($pair, '=');
        self::assertIsInt($equals);

        return substr($pair, $equals + 1);
    }

    private function registrationPolicy(bool $enabled): DynamicRegistrationPolicy
    {
        $policy = $this->createStub(DynamicRegistrationPolicy::class);
        $policy->method('isEnabled')->willReturn($enabled);

        return $policy;
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
