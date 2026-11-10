<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Middleware;

use Doctrine\DBAL\Result;
use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use MarekSkopal\MsMcpServer\Middleware\McpServerMiddleware;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\OAuth\PkceVerifier;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use Mcp\Server\Server;
use Mcp\Server\Session\InMemorySessionStore;
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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpServerMiddleware::class)]
final class McpServerMiddlewareTest extends TestCase
{
    public function testNonMcpPathPassesThrough(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/some-page');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = new McpServerMiddleware(
            $this->createAuthorizationService(),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $this->createPathProvider(),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );

        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    public function testOptionsRequestReturns204WithCorsHeaders(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/mcp');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('OPTIONS');

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = new McpServerMiddleware(
            $this->createAuthorizationService(),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $this->createPathProvider(),
            $responseFactory,
            $this->createStub(StreamFactoryInterface::class),
        );

        $middleware->process($request, $handler);
    }

    public function testCustomBasePathPassesThroughWhenDefaultMcpRequested(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/mcp');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($expectedResponse);

        $middleware = new McpServerMiddleware(
            $this->createAuthorizationService(),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $this->createPathProvider('/typo3-mcp'),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );

        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    public function testMcpPathWithoutAuthReturns401(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/mcp');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('localhost');
        $uri->method('getPort')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeaderLine')->willReturn('');
        $request->method('getServerParams')->willReturn([]);

        $stream = $this->createStub(StreamInterface::class);
        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = new McpServerMiddleware(
            $this->createAuthorizationService(),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $this->createPathProvider(),
            $responseFactory,
            $streamFactory,
        );

        $middleware->process($request, $handler);
    }

    public function testMcpPathWithInvalidTokenReturns401(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/mcp');
        $uri->method('getScheme')->willReturn('https');
        $uri->method('getHost')->willReturn('localhost');
        $uri->method('getPort')->willReturn(7196);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getHeaderLine')->willReturn('Bearer invalid-token');

        // AuthorizationService with no matching token in DB
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'dummy'");
        $queryBuilder->method('executeQuery')->willReturn($result);
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $authorizationService = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($connectionPool),
            $this->createStub(ExtensionConfiguration::class),
        );

        $stream = $this->createStub(StreamInterface::class);
        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = new McpServerMiddleware(
            $authorizationService,
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $this->createPathProvider(),
            $responseFactory,
            $streamFactory,
        );

        $middleware->process($request, $handler);
    }

    public function testMalformedSessionIdReturns400(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/mcp');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getServerParams')->willReturn([]);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match ($name) {
                'Authorization' => 'Bearer valid-token',
                'Mcp-Session-Id' => 'not-a-uuid',
                default => '',
            },
        );

        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(['be_user' => 1, 'access_token_expires' => time() + 9999, 'revoked' => 0]);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);
        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'dummy'");
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('getRestrictions')->willReturn($this->createStub(QueryRestrictionContainerInterface::class));

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $authorizationService = new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($connectionPool),
            $this->createStub(ExtensionConfiguration::class),
        );

        $stream = $this->createStub(StreamInterface::class);
        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $capturedStatus = null;
        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();
        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturnCallback(
            function (int $status = 200) use ($response, &$capturedStatus): ResponseInterface {
                $capturedStatus = $status;

                return $response;
            },
        );

        $mcpServerFactory = $this->createMock(McpServerFactory::class);
        $mcpServerFactory->expects(self::never())->method('create');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = new McpServerMiddleware(
            $authorizationService,
            $this->createStub(BackendUserBootstrap::class),
            $mcpServerFactory,
            $this->createPathProvider(),
            $responseFactory,
            $streamFactory,
        );

        $middleware->process($request, $handler);

        self::assertSame(400, $capturedStatus);
    }

    public function testIsSessionNotFoundResponseDetectsSdkSessionExpired(): void
    {
        $middleware = new McpServerMiddleware(
            $this->createAuthorizationService(),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $this->createPathProvider(),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );

        $reflection = new \ReflectionMethod($middleware, 'isSessionNotFoundResponse');

        $sessionExpired = $this->makeResponse(
            404,
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Session not found or has expired."}}',
        );
        self::assertTrue($reflection->invoke($middleware, $sessionExpired));

        $okResponse = $this->makeResponse(200, '{"jsonrpc":"2.0","id":1,"result":{}}');
        self::assertFalse($reflection->invoke($middleware, $okResponse));

        $methodNotFound = $this->makeResponse(
            404,
            '{"jsonrpc":"2.0","id":1,"error":{"code":-32601,"message":"Method not found"}}',
        );
        self::assertFalse($reflection->invoke($middleware, $methodNotFound));
    }

    public function testSessionIsBoundToTheBackendUserThatOpenedIt(): void
    {
        $middleware = $this->createMiddlewareOverRealServer();

        $initialize = $middleware->process(
            $this->createMcpRequest('POST', 'token-user-1', null, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'test', 'version' => '1.0'],
                ],
            ]),
            $this->createStub(RequestHandlerInterface::class),
        );
        self::assertSame(200, $initialize->getStatusCode());
        $sessionId = $initialize->getHeaderLine('Mcp-Session-Id');
        self::assertNotSame('', $sessionId);

        $ping = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'];

        $ownPing = $middleware->process(
            $this->createMcpRequest('POST', 'token-user-1', $sessionId, $ping),
            $this->createStub(RequestHandlerInterface::class),
        );
        self::assertSame(200, $ownPing->getStatusCode());

        // Another backend user's valid token cannot use the session; answered like an unknown session.
        $foreignPing = $middleware->process(
            $this->createMcpRequest('POST', 'token-user-2', $sessionId, $ping),
            $this->createStub(RequestHandlerInterface::class),
        );
        self::assertSame(401, $foreignPing->getStatusCode());
    }

    public function testDeleteOfUnknownSessionKeepsSdk404(): void
    {
        $response = $this->createMiddlewareOverRealServer()->process(
            $this->createMcpRequest('DELETE', 'token-user-1', '0193b1f2-6c3e-7d4a-8b5c-9d0e1f2a3b4c', null),
            $this->createStub(RequestHandlerInterface::class),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /** One shared session store, as the database store is across requests. */
    private function createMiddlewareOverRealServer(): McpServerMiddleware
    {
        $authorizationService = $this->createStub(AuthorizationService::class);
        $authorizationService->method('validateAccessToken')->willReturnCallback(
            static fn(string $token): int => $token === 'token-user-1' ? 1 : 2,
        );

        $sessionStore = new InMemorySessionStore();
        $mcpServerFactory = $this->createStub(McpServerFactory::class);
        $mcpServerFactory->method('create')->willReturnCallback(
            static fn(): Server => Server::builder()
                ->setServerInfo('test', '1.0')
                ->setSession($sessionStore)
                ->build(),
        );

        return new McpServerMiddleware(
            $authorizationService,
            $this->createStub(BackendUserBootstrap::class),
            $mcpServerFactory,
            $this->createPathProvider(),
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    /** @param array<string, mixed>|null $message */
    private function createMcpRequest(string $method, string $token, ?string $sessionId, ?array $message): ServerRequestInterface
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2025-06-18',
        ];
        if ($sessionId !== null) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        $body = (new StreamFactory())->createStream($message === null ? '' : json_encode($message, JSON_THROW_ON_ERROR));

        return new ServerRequest('https://example.com/mcp', $method, $body, $headers);
    }

    private function makeResponse(int $status, string $body): ResponseInterface
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }

    private function createAuthorizationService(): AuthorizationService
    {
        $connectionPool = $this->createStub(ConnectionPool::class);

        return new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($connectionPool),
            $this->createStub(ExtensionConfiguration::class),
        );
    }

    private function createPathProvider(string $basePath = '/mcp'): McpPathProvider
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['mcpBasePath' => $basePath]);

        return new McpPathProvider($extensionConfiguration);
    }
}
