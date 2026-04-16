<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Middleware;

use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use MarekSkopal\MsMcpServer\Authentication\TokenAuthenticator;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use const JSON_THROW_ON_ERROR;

final readonly class McpServerMiddleware implements MiddlewareInterface
{
    private const string MCP_PATH = '/mcp';

    public function __construct(
        private TokenAuthenticator $tokenAuthenticator,
        private BackendUserBootstrap $backendUserBootstrap,
        private McpServerFactory $mcpServerFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== self::MCP_PATH) {
            return $handler->handle($request);
        }

        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->createJsonResponse(401, ['error' => 'Missing or invalid Authorization header']);
        }

        try {
            $beUserUid = $this->tokenAuthenticator->authenticate($token);
            $this->backendUserBootstrap->bootstrap($beUserUid);
        } catch (\RuntimeException $e) {
            return $this->createJsonResponse(401, ['error' => $e->getMessage()]);
        }

        $server = $this->mcpServerFactory->create();
        $transport = new StreamableHttpTransport($request, $this->responseFactory, $this->streamFactory);

        /** @var ResponseInterface $response */
        $response = $server->run($transport);

        return $response;
    }

    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            $serverParams = $request->getServerParams();
            if (is_string($serverParams['HTTP_AUTHORIZATION'] ?? null)) {
                $authHeader = $serverParams['HTTP_AUTHORIZATION'];
            } elseif (is_string($serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
                $authHeader = $serverParams['REDIRECT_HTTP_AUTHORIZATION'];
            }
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        return $token !== '' ? $token : null;
    }

    /** @param array<string, string> $data */
    private function createJsonResponse(int $statusCode, array $data): ResponseInterface
    {
        $body = $this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }
}
