<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Middleware;

use MarekSkopal\MsMcpServer\Middleware\OAuthMiddleware;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\OAuth\RateLimitService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
        $request = $this->createRequest('/.well-known/oauth-authorization-server', 'GET');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = $this->createMiddlewareWithCapture();
        $middleware->process($request, $handler);

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('authorization_endpoint', $body);
        self::assertStringContainsString('token_endpoint', $body);
        self::assertStringContainsString('S256', $body);
    }

    public function testResourceMetadataEndpointReturnsResourceConfig(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/.well-known/oauth-protected-resource');
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

        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('/mcp', $body);
        self::assertStringContainsString('authorization_servers', $body);
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

    private function createMiddleware(): OAuthMiddleware
    {
        return new OAuthMiddleware(
            $this->createStub(AuthorizationService::class),
            $this->createStub(ClientRepository::class),
            $this->createStub(ConnectionPool::class),
            $this->createStub(PasswordHashFactory::class),
            $this->createStub(RateLimitService::class),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );
    }

    private function createMiddlewareWithCapture(?RateLimitService $rateLimitService = null): OAuthMiddleware
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
            $rateLimitService ?? $this->createStub(RateLimitService::class),
            $responseFactory,
            $streamFactory,
        );
    }
}
