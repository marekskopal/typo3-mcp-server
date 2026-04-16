<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Middleware;

use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use MarekSkopal\MsMcpServer\Authentication\TokenAuthenticator;
use MarekSkopal\MsMcpServer\Middleware\McpServerMiddleware;
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
            $this->createStub(TokenAuthenticator::class),
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );

        self::assertSame($expectedResponse, $middleware->process($request, $handler));
    }

    public function testMcpPathWithoutAuthReturns401(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/mcp');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('');

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
            $this->createStub(TokenAuthenticator::class),
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

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->with('Authorization')->willReturn('Bearer invalid-token');

        $tokenAuthenticator = $this->createMock(TokenAuthenticator::class);
        $tokenAuthenticator->method('authenticate')
            ->with('invalid-token')
            ->willThrowException(new \RuntimeException('Invalid token'));

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
            $tokenAuthenticator,
            $this->createStub(BackendUserBootstrap::class),
            $this->createStub(McpServerFactory::class),
            $responseFactory,
            $streamFactory,
        );

        $middleware->process($request, $handler);
    }
}
