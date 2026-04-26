<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Middleware;

use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use const JSON_THROW_ON_ERROR;

readonly class McpServerMiddleware implements MiddlewareInterface
{
    private const string MCP_PATH = '/mcp';

    public function __construct(
        private AuthorizationService $authorizationService,
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

        // Handle CORS preflight without auth
        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCorsHeaders($this->responseFactory->createResponse(204));
        }

        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->withCorsHeaders($this->createUnauthorizedResponse($request, 'Missing or invalid Authorization header'));
        }

        try {
            $beUserUid = $this->authorizationService->validateAccessToken($token);
            $this->backendUserBootstrap->bootstrap($beUserUid);
        } catch (\RuntimeException) {
            return $this->withCorsHeaders($this->createUnauthorizedResponse($request, 'Authentication failed'));
        }

        $server = $this->mcpServerFactory->create();
        $transport = new StreamableHttpTransport($request, $this->responseFactory, $this->streamFactory);

        /** @var ResponseInterface $response */
        $response = $server->run($transport);

        return $this->withCorsHeaders($response);
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

    private function createUnauthorizedResponse(ServerRequestInterface $request, string $error): ResponseInterface
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUrl .= ':' . $uri->getPort();
        }

        $resourceMetadataUrl = $baseUrl . '/.well-known/oauth-protected-resource';

        $body = $this->streamFactory->createStream(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $resourceMetadataUrl))
            ->withBody($body);
    }

    private function withCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader(
                'Access-Control-Allow-Headers',
                'Accept, Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID',
            )
            ->withHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
