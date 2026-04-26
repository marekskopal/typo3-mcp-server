<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Middleware;

use Doctrine\DBAL\ParameterType;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\OAuth\RateLimitService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

readonly class OAuthMiddleware implements MiddlewareInterface
{
    private const string METADATA_PATH = '/.well-known/oauth-authorization-server';

    private const string AUTHORIZE_PATH = '/mcp/oauth/authorize';

    private const string TOKEN_PATH = '/mcp/oauth/token';

    private const string REGISTER_PATH = '/mcp/oauth/register';

    private const string REVOKE_PATH = '/mcp/oauth/revoke';

    private const string RESOURCE_METADATA_PATH = '/.well-known/oauth-protected-resource';

    public function __construct(
        private AuthorizationService $authorizationService,
        private ClientRepository $clientRepository,
        private ConnectionPool $connectionPool,
        private PasswordHashFactory $passwordHashFactory,
        private RateLimitService $rateLimitService,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        $rateLimitEndpoint = match (true) {
            $path === self::AUTHORIZE_PATH && $method === 'POST' => 'authorize_post',
            $path === self::AUTHORIZE_PATH && $method === 'GET' => 'authorize_get',
            $path === self::TOKEN_PATH && $method === 'POST' => 'token_post',
            $path === self::REGISTER_PATH && $method === 'POST' => 'register_post',
            $path === self::REVOKE_PATH && $method === 'POST' => 'revoke_post',
            default => null,
        };

        if ($rateLimitEndpoint !== null) {
            $retryAfter = $this->rateLimitService->check($this->resolveIpAddress($request), $rateLimitEndpoint);
            if ($retryAfter !== null) {
                return $this->createJsonResponse(429, [
                    'error' => 'too_many_requests',
                    'error_description' => 'Rate limit exceeded. Try again later.',
                ])->withHeader('Retry-After', (string) $retryAfter);
            }
        }

        return match (true) {
            $path === self::METADATA_PATH && $method === 'GET' => $this->handleMetadata($request),
            $path === self::RESOURCE_METADATA_PATH && $method === 'GET' => $this->handleResourceMetadata($request),
            $path === self::AUTHORIZE_PATH && $method === 'GET' => $this->handleAuthorizeGet($request),
            $path === self::AUTHORIZE_PATH && $method === 'POST' => $this->handleAuthorizePost($request),
            $path === self::TOKEN_PATH && $method === 'POST' => $this->handleToken($request),
            $path === self::REGISTER_PATH && $method === 'POST' => $this->handleRegister($request),
            $path === self::REVOKE_PATH && $method === 'POST' => $this->handleRevoke($request),
            default => $handler->handle($request),
        };
    }

    private function handleMetadata(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUrl .= ':' . $uri->getPort();
        }

        $metadata = [
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl . self::AUTHORIZE_PATH,
            'token_endpoint' => $baseUrl . self::TOKEN_PATH,
            'registration_endpoint' => $baseUrl . self::REGISTER_PATH,
            'revocation_endpoint' => $baseUrl . self::REVOKE_PATH,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ];

        return $this->createJsonResponse(200, $metadata);
    }

    private function handleResourceMetadata(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUrl .= ':' . $uri->getPort();
        }

        $metadata = [
            'resource' => $baseUrl . '/mcp',
            'authorization_servers' => [$baseUrl],
        ];

        return $this->createJsonResponse(200, $metadata);
    }

    private function handleAuthorizeGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $params */
        $params = $request->getQueryParams();

        $error = $this->validateAuthorizeParams($params);
        if ($error !== null) {
            return $this->createJsonResponse(400, ['error' => 'invalid_request', 'error_description' => $error]);
        }

        $clientId = is_string($params['client_id'] ?? null) ? $params['client_id'] : '';
        $client = $this->clientRepository->findByClientId($clientId);
        $clientName = $client !== null ? $client['client_name'] : 'Unknown';

        $csrfToken = bin2hex(random_bytes(32));

        $html = $this->renderAuthorizeForm($clientName, $params, $csrfToken);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Set-Cookie', sprintf(
                'mcp_csrf=%s; Path=/mcp/oauth; HttpOnly; SameSite=Strict; Secure; Max-Age=600',
                $csrfToken,
            ))
            ->withBody($this->streamFactory->createStream($html));
    }

    private function handleAuthorizePost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = $request->getParsedBody() ?? [];

        $csrfToken = (string) ($body['csrf_token'] ?? '');
        $cookieCsrf = $this->extractCsrfFromCookie($request);
        if ($csrfToken === '' || !hash_equals($cookieCsrf, $csrfToken)) {
            return $this->createJsonResponse(403, ['error' => 'invalid_request', 'error_description' => 'CSRF validation failed']);
        }

        $clientId = (string) ($body['client_id'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');

        // Re-validate redirect_uri against registered client URIs to prevent POST manipulation
        if ($redirectUri === '' || !$this->clientRepository->validateRedirectUri($clientId, $redirectUri)) {
            return $this->createJsonResponse(400, ['error' => 'invalid_request', 'error_description' => 'Invalid redirect_uri']);
        }

        $username = (string) ($body['username'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $codeChallenge = (string) ($body['code_challenge'] ?? '');
        $codeChallengeMethod = (string) ($body['code_challenge_method'] ?? '');
        $state = (string) ($body['state'] ?? '');

        $beUserUid = $this->authenticateBackendUser($username, $password);
        if ($beUserUid === null) {
            $client = $this->clientRepository->findByClientId($clientId);
            $clientName = $client !== null ? (string) $client['client_name'] : 'Unknown';

            $newCsrfToken = bin2hex(random_bytes(32));
            $params = [
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => $codeChallengeMethod,
                'state' => $state,
                'response_type' => 'code',
            ];

            $html = $this->renderAuthorizeForm($clientName, $params, $newCsrfToken, 'Invalid username or password.');

            return $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Set-Cookie', sprintf(
                    'mcp_csrf=%s; Path=/mcp/oauth; HttpOnly; SameSite=Strict; Secure; Max-Age=600',
                    $newCsrfToken,
                ))
                ->withBody($this->streamFactory->createStream($html));
        }

        try {
            $code = $this->authorizationService->createAuthorizationCode(
                $clientId,
                $beUserUid,
                $codeChallenge,
                $codeChallengeMethod,
                $redirectUri,
            );
        } catch (\RuntimeException $e) {
            return $this->createJsonResponse(400, ['error' => 'server_error', 'error_description' => $e->getMessage()]);
        }

        $redirectTarget = $redirectUri . '?' . http_build_query(array_filter([
            'code' => $code,
            'state' => $state !== '' ? $state : null,
        ]));

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $redirectTarget)
            ->withHeader('Set-Cookie', 'mcp_csrf=; Path=/mcp/oauth; HttpOnly; SameSite=Strict; Secure; Max-Age=0');
    }

    private function handleToken(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = $request->getParsedBody() ?? [];
        $grantType = (string) ($body['grant_type'] ?? '');

        try {
            $tokenPair = match ($grantType) {
                'authorization_code' => $this->authorizationService->exchangeCode(
                    code: (string) ($body['code'] ?? ''),
                    codeVerifier: (string) ($body['code_verifier'] ?? ''),
                    clientId: (string) ($body['client_id'] ?? ''),
                    redirectUri: (string) ($body['redirect_uri'] ?? ''),
                ),
                'refresh_token' => $this->authorizationService->refreshToken(
                    refreshToken: (string) ($body['refresh_token'] ?? ''),
                    clientId: (string) ($body['client_id'] ?? ''),
                ),
                default => throw new \RuntimeException('Unsupported grant type', 1712100040),
            };
        } catch (\RuntimeException $e) {
            return $this->createJsonResponse(400, ['error' => 'invalid_grant', 'error_description' => $e->getMessage()]);
        }

        return $this->createJsonResponse(200, [
            'access_token' => $tokenPair->accessToken,
            'token_type' => $tokenPair->tokenType,
            'expires_in' => $tokenPair->expiresIn,
            'refresh_token' => $tokenPair->refreshToken,
        ]);
    }

    private function handleRegister(ServerRequestInterface $request): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'Content-Type must be application/json'],
            );
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);

        $clientName = is_string($body['client_name'] ?? null) ? $body['client_name'] : 'MCP Client';

        $redirectUris = [];
        if (is_array($body['redirect_uris'] ?? null)) {
            foreach ($body['redirect_uris'] as $uri) {
                if (is_string($uri) && $uri !== '') {
                    $redirectUris[] = $uri;
                }
            }
        }

        if ($redirectUris === []) {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'At least one redirect_uri is required'],
            );
        }

        $client = $this->clientRepository->registerClient($clientName, $redirectUris);

        return $this->createJsonResponse(201, [
            'client_id' => $client['client_id'],
            'client_name' => $client['client_name'],
            'redirect_uris' => $client['redirect_uris'],
            'token_endpoint_auth_method' => 'none',
        ]);
    }

    private function handleRevoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $body */
        $body = $request->getParsedBody() ?? [];
        $token = (string) ($body['token'] ?? '');

        if ($token === '') {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'token parameter is required'],
            );
        }

        $this->authorizationService->revokeToken($token);

        // RFC 7009: always return 200 OK regardless of whether the token was found
        return $this->createJsonResponse(200, []);
    }

    /** @param array<string, mixed> $params */
    private function validateAuthorizeParams(array $params): ?string
    {
        if (($params['response_type'] ?? '') !== 'code') {
            return 'response_type must be "code"';
        }

        $clientId = is_string($params['client_id'] ?? null) ? $params['client_id'] : '';
        if ($clientId === '') {
            return 'client_id is required';
        }

        $client = $this->clientRepository->findByClientId($clientId);
        if ($client === null) {
            return 'Unknown client_id';
        }

        $redirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';
        if ($redirectUri === '') {
            return 'redirect_uri is required';
        }

        if (!$this->clientRepository->validateRedirectUri($clientId, $redirectUri)) {
            return 'Invalid redirect_uri';
        }

        if (($params['code_challenge_method'] ?? '') !== 'S256') {
            return 'code_challenge_method must be "S256"';
        }

        if (($params['code_challenge'] ?? '') === '') {
            return 'code_challenge is required';
        }

        return null;
    }

    private function authenticateBackendUser(string $username, string $password): ?int
    {
        if ($username === '' || $password === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        /** @var array{uid: int|string, password: string}|false $row */
        $row = $queryBuilder
            ->select('uid', 'password')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $hashInstance = $this->passwordHashFactory->get($row['password'], 'BE');
        if (!$hashInstance->checkPassword($password, $row['password'])) {
            return null;
        }

        return (int) $row['uid'];
    }

    /** @param array<string, mixed> $params */
    private function renderAuthorizeForm(string $clientName, array $params, string $csrfToken, string $errorMessage = '',): string
    {
        $clientNameEscaped = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
        $errorHtml = $errorMessage !== ''
            ? '<div style="background:#dc3545;color:#fff;padding:10px;border-radius:4px;margin-bottom:16px">'
                . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        $hiddenFields = '';
        foreach (['client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state'] as $field) {
            $rawValue = $params[$field] ?? '';
            $value = htmlspecialchars(is_string($rawValue) ? $rawValue : '', ENT_QUOTES, 'UTF-8');
            $hiddenFields .= sprintf('<input type="hidden" name="%s" value="%s" />', $field, $value);
        }

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>TYPO3 MCP Server - Authorization</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1a1a2e; color: #eee; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
                    .card { background: #16213e; border-radius: 8px; padding: 32px; width: 100%; max-width: 400px; box-shadow: 0 4px 24px rgba(0,0,0,0.3); }
                    h1 { font-size: 20px; margin: 0 0 8px; }
                    p { color: #aaa; font-size: 14px; margin: 0 0 24px; }
                    label { display: block; font-size: 13px; color: #ccc; margin-bottom: 4px; }
                    input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #333; border-radius: 4px; background: #0f3460; color: #eee; font-size: 14px; box-sizing: border-box; margin-bottom: 16px; }
                    button { width: 100%; padding: 12px; background: #e94560; border: none; border-radius: 4px; color: #fff; font-size: 15px; cursor: pointer; font-weight: 600; }
                    button:hover { background: #c73a52; }
                    .client-name { color: #e94560; font-weight: 600; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>Authorize Application</h1>
                    <p><span class="client-name">{$clientNameEscaped}</span> is requesting access to your TYPO3 backend account.</p>
                    {$errorHtml}
                    <form method="post" action="/mcp/oauth/authorize">
                        {$hiddenFields}
                        <input type="hidden" name="csrf_token" value="{$csrfToken}" />
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autofocus />
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required />
                        <button type="submit">Authorize</button>
                    </form>
                </div>
            </body>
            </html>
            HTML;
    }

    private function resolveIpAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRemoteAddress() : '';
    }

    private function extractCsrfFromCookie(ServerRequestInterface $request): string
    {
        $cookies = $request->getCookieParams();

        return is_string($cookies['mcp_csrf'] ?? null) ? $cookies['mcp_csrf'] : '';
    }

    /** @param array<string, mixed> $data */
    private function createJsonResponse(int $statusCode, array $data): ResponseInterface
    {
        $body = $this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR));

        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($body);
    }
}
