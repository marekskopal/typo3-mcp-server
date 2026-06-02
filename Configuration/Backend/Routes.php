<?php

declare(strict_types=1);

use MarekSkopal\MsMcpServer\Controller\OAuthBridgeController;

return [
    /*
     * Post-login bridge for the OAuth authorize flow.
     *
     * When `OAuthMiddleware::handleAuthorizeGet` sees an unauthenticated request, it
     * redirects the browser to `/typo3/login` with TYPO3's standard `?redirect=<name>&redirectParams=<array>`
     * carrier (consumed by `LoginController::checkRedirect`). After a successful login,
     * `BackendController::mainAction` resolves the `RouteRedirect` and lands the user
     * here; we then 302 the browser back to the frontend authorize endpoint, where the
     * consent screen is rendered using the freshly-established backend session.
     *
     * The `redirect.parameters` whitelist below is the trust boundary: `RouteRedirect::resolve`
     * intersects incoming `redirectParams` against it, silently dropping anything else.
     */
    'msmcpserver_oauth_bridge' => [
        'path' => '/mcp/oauth/bridge',
        'target' => OAuthBridgeController::class . '::indexAction',
        'redirect' => [
            'enable' => true,
            'parameters' => [
                'response_type' => true,
                'client_id' => true,
                'redirect_uri' => true,
                'code_challenge' => true,
                'code_challenge_method' => true,
                'state' => true,
                'scope' => true,
            ],
        ],
    ],
];
