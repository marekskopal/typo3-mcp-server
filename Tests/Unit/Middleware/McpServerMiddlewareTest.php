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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

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

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(204)->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = new McpServerMiddleware(
            $this->createAuthorizationService(),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $responseFactory,
            $this->createStub(StreamFactoryInterface::class),
        );

        $middleware->process($request, $handler);
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
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');
        $request->method('getServerParams')->willReturn([]);

        $stream = $this->createStub(StreamInterface::class);
        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(401)->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = new McpServerMiddleware(
            $this->createAuthorizationService(),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
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
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer invalid-token');

        // AuthorizationService with no matching token in DB
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
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
        );

        $stream = $this->createStub(StreamInterface::class);
        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('withBody')->willReturnSelf();

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(401)->willReturn($response);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $middleware = new McpServerMiddleware(
            $authorizationService,
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $responseFactory,
            $streamFactory,
        );

        $middleware->process($request, $handler);
    }

    private function createAuthorizationService(): AuthorizationService
    {
        $connectionPool = $this->createStub(ConnectionPool::class);

        return new AuthorizationService(
            $connectionPool,
            new PkceVerifier(),
            new ClientRepository($connectionPool),
        );
    }
}
