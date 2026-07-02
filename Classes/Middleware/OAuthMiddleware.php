<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Middleware;

use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use MarekSkopal\MsMcpServer\OAuth\AuthorizeParamsValidator;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\OAuth\OAuthContinuationCookie;
use MarekSkopal\MsMcpServer\OAuth\RateLimitService;
use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/**
 * Routes the OAuth endpoints. Authentication is delegated to the real TYPO3 backend
 * login form via a top-level redirect to `/typo3/login`. We can't use TYPO3's
 * `RouteRedirect` post-login carrier because it lands the user inside the backend's
 * content iframe (`BackendController::mainAction` assigns the resolved redirect to
 * `startupModule`, which is loaded into the `<iframe>` shell); the final 302 to a
 * desktop-OAuth localhost callback would be blocked by `frame-src 'self'` /
 * `form-action 'self'`. Instead we set a short-lived HMAC-signed cookie remembering
 * the authorize URL, and a sibling backend-stack middleware bounce intercepts
 * `/typo3/main` to issue a top-level 302 back to it before the dashboard renders.
 */
readonly class OAuthMiddleware implements MiddlewareInterface
{
    private const string BACKEND_LOGIN_PATH = '/typo3/login';

    private const string BACKEND_POST_LOGIN_PATH = '/typo3/main';

    public function __construct(
        private AuthorizationService $authorizationService,
        private ClientRepository $clientRepository,
        private AuthorizeParamsValidator $authorizeParamsValidator,
        private OAuthContinuationCookie $continuationCookie,
        private McpPathProvider $pathProvider,
        private RateLimitService $rateLimitService,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        if ($path === self::BACKEND_POST_LOGIN_PATH && $method === 'GET') {
            $bounce = $this->handleBackendPostLoginBounce($request);
            if ($bounce !== null) {
                return $bounce;
            }
        }

        $authorizePath = $this->pathProvider->getAuthorizePath();
        $tokenPath = $this->pathProvider->getTokenPath();
        $registerPath = $this->pathProvider->getRegisterPath();
        $revokePath = $this->pathProvider->getRevokePath();
        $metadataPath = $this->pathProvider->getMetadataPath();
        $resourceMetadataPath = $this->pathProvider->getResourceMetadataPath();

        $rateLimitEndpoint = match (true) {
            $path === $authorizePath && $method === 'POST' => 'authorize_post',
            $path === $authorizePath && $method === 'GET' => 'authorize_get',
            $path === $tokenPath && $method === 'POST' => 'token_post',
            $path === $registerPath && $method === 'POST' => 'register_post',
            $path === $revokePath && $method === 'POST' => 'revoke_post',
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
            $path === $metadataPath && $method === 'GET' => $this->handleMetadata($request),
            $path === $resourceMetadataPath && $method === 'GET' => $this->handleResourceMetadata($request),
            $path === $authorizePath && $method === 'GET' => $this->handleAuthorizeGet($request),
            $path === $authorizePath && $method === 'POST' => $this->handleAuthorizePost($request),
            $path === $tokenPath && $method === 'POST' => $this->handleToken($request),
            $path === $registerPath && $method === 'POST' => $this->handleRegister($request),
            $path === $revokePath && $method === 'POST' => $this->handleRevoke($request),
            default => $handler->handle($request),
        };
    }

    private function handleMetadata(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $issuer = $baseUrl . $this->pathProvider->getBasePath();

        $metadata = [
            'issuer' => $issuer,
            'authorization_endpoint' => $baseUrl . $this->pathProvider->getAuthorizePath(),
            'token_endpoint' => $baseUrl . $this->pathProvider->getTokenPath(),
            'registration_endpoint' => $baseUrl . $this->pathProvider->getRegisterPath(),
            'revocation_endpoint' => $baseUrl . $this->pathProvider->getRevokePath(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ];

        return $this->createJsonResponse(200, $metadata);
    }

    private function handleResourceMetadata(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->resolveBaseUrl($request);
        $resource = $baseUrl . $this->pathProvider->getBasePath();

        $metadata = [
            'resource' => $resource,
            'authorization_servers' => [$resource],
        ];

        return $this->createJsonResponse(200, $metadata);
    }

    private function resolveBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUrl .= ':' . $uri->getPort();
        }

        return $baseUrl;
    }

    private function handleAuthorizeGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $params */
        $params = $request->getQueryParams();

        $error = $this->authorizeParamsValidator->validate($params);
        if ($error !== null) {
            return $this->createJsonResponse(400, ['error' => 'invalid_request', 'error_description' => $error]);
        }

        $beUserUid = $this->resolveAuthenticatedBackendUserUid($request);
        if ($beUserUid === null) {
            return $this->redirectToBackendLogin($request, $params);
        }

        $username = $this->resolveBackendUsername($request);

        $clientId = is_string($params['client_id'] ?? null) ? $params['client_id'] : '';
        $client = $this->clientRepository->findByClientId($clientId);
        $clientName = $client !== null ? (string) $client['client_name'] : 'Unknown';

        $csrfToken = bin2hex(random_bytes(32));
        $html = $this->renderConsentForm($clientName, $username, $params, $csrfToken);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            // Prevent the consent screen from being framed (clickjacking the "Authorize"
            // button) or cached (it carries a live CSRF token and reflected params).
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "frame-ancestors 'none'")
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Set-Cookie', sprintf(
                'mcp_csrf=%s; Path=%s; HttpOnly; SameSite=Strict; Secure; Max-Age=600',
                $csrfToken,
                $this->pathProvider->getOAuthCookiePath(),
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

        $beUserUid = $this->resolveAuthenticatedBackendUserUid($request);
        if ($beUserUid === null) {
            return $this->createJsonResponse(401, [
                'error' => 'login_required',
                'error_description' => 'Backend session expired. Please restart the authorization flow.',
            ]);
        }

        $codeChallenge = (string) ($body['code_challenge'] ?? '');
        $codeChallengeMethod = (string) ($body['code_challenge_method'] ?? '');
        $state = (string) ($body['state'] ?? '');

        // The POST is the path that actually mints the code, so re-run the full authorize
        // validation here (not just on the GET). Otherwise a crafted POST could issue a code
        // bound to an empty or non-S256 PKCE challenge, dropping the PKCE guarantee.
        $validationError = $this->authorizeParamsValidator->validate([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
        ]);
        if ($validationError !== null) {
            return $this->createJsonResponse(400, ['error' => 'invalid_request', 'error_description' => $validationError]);
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
            ->withHeader('Set-Cookie', sprintf(
                'mcp_csrf=; Path=%s; HttpOnly; SameSite=Strict; Secure; Max-Age=0',
                $this->pathProvider->getOAuthCookiePath(),
            ));
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
            // Log the specific failure server-side, but return a single generic invalid_grant
            // so the client can't tell unknown vs. expired vs. mismatched-client vs. failed-PKCE.
            $this->logger->info('OAuth token request rejected', ['reason' => $e->getMessage(), 'grant_type' => $grantType]);

            return $this->createJsonResponse(400, [
                'error' => 'invalid_grant',
                'error_description' => 'The authorization grant is invalid, expired, or revoked.',
            ]);
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

        try {
            $body = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'Request body must be valid JSON'],
            );
        }

        if (!is_array($body)) {
            return $this->createJsonResponse(
                400,
                ['error' => 'invalid_request', 'error_description' => 'Request body must be a JSON object'],
            );
        }

        /** @var array<string, mixed> $body */
        $clientName = is_string($body['client_name'] ?? null) ? $body['client_name'] : 'MCP Client';
        // The client_name column is varchar(255); cap the attacker-controlled value so a long name
        // cannot trigger a database error (500) instead of a clean registration.
        if (mb_strlen($clientName) > 255) {
            $clientName = mb_substr($clientName, 0, 255);
        }

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

        $redirectUriError = $this->clientRepository->validateRedirectUrisForRegistration($redirectUris);
        if ($redirectUriError !== null) {
            return $this->createJsonResponse(400, ['error' => 'invalid_redirect_uri', 'error_description' => $redirectUriError]);
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

    /**
     * Bootstraps a `BackendUserAuthentication` from the request's cookies (TYPO3's
     * `be_typo_user` cookie is scoped to the site path, so it reaches the frontend
     * stack on standard installs). Returns the authenticated uid or null.
     */
    private function resolveAuthenticatedBackendUserUid(ServerRequestInterface $request): ?int
    {
        $beUser = $this->resolveBackendUser($request);
        $uid = $beUser->getUserId();

        return $uid !== null && $uid > 0 ? $uid : null;
    }

    private function resolveBackendUsername(ServerRequestInterface $request): string
    {
        return $this->resolveBackendUser($request)->getUserName() ?? '';
    }

    private function resolveBackendUser(ServerRequestInterface $request): BackendUserAuthentication
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser instanceof BackendUserAuthentication) {
            return $beUser;
        }

        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $beUser->start($request);
        $GLOBALS['BE_USER'] = $beUser;

        return $beUser;
    }

    /** @param array<string, mixed> $params */
    private function redirectToBackendLogin(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $authorizeUrl = $this->pathProvider->getAuthorizePath() . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => is_string($params['client_id'] ?? null) ? $params['client_id'] : '',
            'redirect_uri' => is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '',
            'code_challenge' => is_string($params['code_challenge'] ?? null) ? $params['code_challenge'] : '',
            'code_challenge_method' => is_string($params['code_challenge_method'] ?? null) ? $params['code_challenge_method'] : '',
            'state' => is_string($params['state'] ?? null) ? $params['state'] : '',
            'scope' => is_string($params['scope'] ?? null) ? $params['scope'] : '',
        ]);

        $secure = $request->getUri()->getScheme() === 'https';

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', self::BACKEND_LOGIN_PATH . '?login_status=login')
            ->withHeader('Set-Cookie', $this->continuationCookie->issue($authorizeUrl, $secure));
    }

    /**
     * Intercepts `/typo3/main` after backend login: if the continuation cookie is
     * present and the user is authenticated, top-level 302 back to the authorize
     * URL the cookie remembers (and clear the cookie). Returns null when the cookie
     * is absent, tampered, or the user isn't yet authenticated — letting the request
     * fall through to `BackendController::mainAction` normally.
     */
    private function handleBackendPostLoginBounce(ServerRequestInterface $request): ?ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $rawCookie = is_string($cookies[OAuthContinuationCookie::COOKIE_NAME] ?? null)
            ? $cookies[OAuthContinuationCookie::COOKIE_NAME]
            : null;

        $url = $this->continuationCookie->read($rawCookie);
        if ($url === null) {
            return null;
        }

        // Only follow our own authorize URLs — never bounce anywhere else even with
        // a valid signature, in case the secret ever leaks.
        if (!str_starts_with($url, $this->pathProvider->getAuthorizePath() . '?')) {
            return null;
        }

        if ($this->resolveAuthenticatedBackendUserUid($request) === null) {
            return null;
        }

        $secure = $request->getUri()->getScheme() === 'https';

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $url)
            ->withHeader('Set-Cookie', $this->continuationCookie->clear($secure));
    }

    /** @param array<string, mixed> $params */
    private function renderConsentForm(string $clientName, string $username, array $params, string $csrfToken): string
    {
        $clientNameEscaped = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
        $usernameEscaped = htmlspecialchars($username !== '' ? $username : 'TYPO3 backend user', ENT_QUOTES, 'UTF-8');

        $hiddenFields = '';
        foreach (['client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state'] as $field) {
            $rawValue = $params[$field] ?? '';
            $value = htmlspecialchars(is_string($rawValue) ? $rawValue : '', ENT_QUOTES, 'UTF-8');
            $hiddenFields .= sprintf('<input type="hidden" name="%s" value="%s" />', $field, $value);
        }

        $formAction = htmlspecialchars($this->pathProvider->getAuthorizePath(), ENT_QUOTES, 'UTF-8');
        $cancelHref = $this->buildCancelHref($params);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>TYPO3 MCP Server - Authorization</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1a1a2e; color: #eee; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
                    .card { background: #16213e; border-radius: 8px; padding: 32px; width: 100%; max-width: 420px; box-shadow: 0 4px 24px rgba(0,0,0,0.3); }
                    h1 { font-size: 20px; margin: 0 0 8px; }
                    p { color: #aaa; font-size: 14px; margin: 0 0 16px; }
                    .who { background: #0f3460; padding: 12px 14px; border-radius: 4px; font-size: 13px; margin: 0 0 20px; color: #ccc; }
                    .who strong { color: #fff; }
                    .actions { display: flex; gap: 10px; }
                    button.primary { flex: 1; padding: 12px; background: #e94560; border: none; border-radius: 4px; color: #fff; font-size: 15px; cursor: pointer; font-weight: 600; }
                    button.primary:hover { background: #c73a52; }
                    a.cancel { flex: 1; padding: 12px; background: transparent; border: 1px solid #555; border-radius: 4px; color: #ccc; font-size: 15px; text-align: center; text-decoration: none; line-height: 1.2; }
                    a.cancel:hover { border-color: #888; color: #fff; }
                    .client-name { color: #e94560; font-weight: 600; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>Authorize MCP Access</h1>
                    <p><span class="client-name">{$clientNameEscaped}</span> is requesting access to your TYPO3 backend account.</p>
                    <div class="who">Signed in as <strong>{$usernameEscaped}</strong>.</div>
                    <form method="post" action="{$formAction}">
                        {$hiddenFields}
                        <input type="hidden" name="csrf_token" value="{$csrfToken}" />
                        <div class="actions">
                            <a class="cancel" href="{$cancelHref}">Cancel</a>
                            <button type="submit" class="primary">Authorize Access</button>
                        </div>
                    </form>
                </div>
            </body>
            </html>
            HTML;
    }

    /**
     * Builds the cancel link target: per RFC 6749 §4.1.2.1 the authorization
     * server redirects to the registered `redirect_uri` with `error=access_denied`
     * (plus the original `state` if provided).
     *
     * @param array<string, mixed> $params
     */
    private function buildCancelHref(array $params): string
    {
        $redirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';
        $state = is_string($params['state'] ?? null) ? $params['state'] : '';

        if ($redirectUri === '') {
            return '/';
        }

        $cancelParams = ['error' => 'access_denied'];
        if ($state !== '') {
            $cancelParams['state'] = $state;
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return htmlspecialchars($redirectUri . $separator . http_build_query($cancelParams), ENT_QUOTES, 'UTF-8');
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
