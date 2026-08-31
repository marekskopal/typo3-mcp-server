<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Middleware;

use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Http\NormalizedParams;
use const JSON_THROW_ON_ERROR;

readonly class McpServerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthorizationService $authorizationService,
        private BackendUserBootstrap $backendUserBootstrap,
        private McpServerFactory $mcpServerFactory,
        private McpPathProvider $pathProvider,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== $this->pathProvider->getBasePath()) {
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

        // The SDK calls Uuid::fromString() on this header with no guard; an invalid value would
        // throw out of the middleware as an uncaught 500. Reject it with a clean 400 instead.
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if ($sessionId !== '' && !Uuid::isValid($sessionId)) {
            return $this->withCorsHeaders($this->createBadRequestResponse('Invalid Mcp-Session-Id header'));
        }

        $server = $this->mcpServerFactory->create($beUserUid);
        // Opt out of the SDK's default DnsRebindingProtectionMiddleware: it only allows
        // localhost Host/Origin and would 403 every request to a real TYPO3 deployment.
        // Bearer-token auth above is the actual protection here.
        // ProtocolVersionMiddleware is deliberately NOT listed: since SDK 0.8 the transport
        // applies it itself to handshake-era traffic only. Passing it here would run it before
        // the era is classified and reject every modern-era (2026-07-28) request.
        $transport = new StreamableHttpTransport(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            middleware: [new CorsMiddleware()],
        );

        /** @var ResponseInterface $response */
        $response = $server->run($transport);

        if ($this->isSessionNotFoundResponse($response)) {
            return $this->withCorsHeaders($this->createUnauthorizedResponse($request, 'Session expired'));
        }

        return $this->withCorsHeaders($response);
    }

    /**
     * The SDK returns HTTP 404 with a JSON-RPC body (code -32600, message "Session not found...")
     * for both unknown and expired session IDs. Detect and rewrite to 401 so MCP clients
     * re-run the OAuth + handshake flow automatically.
     */
    private function isSessionNotFoundResponse(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() !== 404) {
            return false;
        }

        $body = (string) $response->getBody();
        $response->getBody()->rewind();

        return $body !== ''
            && str_contains($body, '"code":-32600')
            && str_contains($body, 'Session not found');
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
        $resourceMetadataUrl = $this->resolveBaseUrl($request) . $this->pathProvider->getResourceMetadataPath();

        $body = $this->streamFactory->createStream(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $resourceMetadataUrl))
            ->withBody($body);
    }

    /**
     * The 401 advertises a resource_metadata URL that RFC 9728-aware clients use to bootstrap
     * OAuth discovery. Prefer NormalizedParams::getHttpHost(), which TYPO3 validates against
     * trustedHostsPattern, so a forged Host header cannot redirect discovery to an attacker
     * origin. Fall back to the request URI only when normalizedParams is unavailable.
     */
    private function resolveBaseUrl(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            // getRequestHost() is "scheme://host[:port]" with the host validated against
            // TYPO3's trustedHostsPattern.
            return rtrim($normalizedParams->getRequestHost(), '/');
        }

        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUrl .= ':' . $uri->getPort();
        }

        return $baseUrl;
    }

    private function createBadRequestResponse(string $error): ResponseInterface
    {
        $body = $this->streamFactory->createStream(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse(400)
            ->withHeader('Content-Type', 'application/json')
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
