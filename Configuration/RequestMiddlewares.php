<?php

declare(strict_types=1);

use MarekSkopal\MsMcpServer\Middleware\McpServerMiddleware;
use MarekSkopal\MsMcpServer\Middleware\OAuthMiddleware;

return [
    /*
     * Both MCP middlewares must run *before* `typo3/cms-frontend/site` (and therefore before
     * `typo3/cms-frontend/base-redirect-resolver`, which depends on it). On installations where
     * every language — including the default — carries a URL prefix (e.g. `/de/`), the base
     * redirect resolver 404s any request without a valid language prefix. Since `/mcp` and the
     * `/.well-known/...` discovery paths have no language prefix, they would be rejected before
     * our middlewares ever ran. Running ahead of site resolution lets us short-circuit those
     * paths cleanly; any non-MCP request is passed straight through untouched.
     *
     * The middlewares only read the `normalizedParams` attribute (trusted host / remote IP),
     * which is populated by `typo3/cms-core/normalized-params-attribute` early in the stack, so
     * we anchor to that rather than to site/language resolution.
     */
    'frontend' => [
        'marekskopal/mcp-server-oauth' => [
            'target' => OAuthMiddleware::class,
            'before' => [
                'marekskopal/mcp-server',
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
        'marekskopal/mcp-server' => [
            'target' => McpServerMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'marekskopal/mcp-server-oauth',
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
    /*
     * Backend stack: same middleware also bounces `/typo3/main` requests back to
     * `/mcp/oauth/authorize` when the OAuth continuation cookie is present and the
     * user just logged in. Runs after `cms-backend/authentication` so `$BE_USER` is
     * populated, and before the module validator so we can short-circuit cleanly
     * before the backend dashboard renders.
     */
    'backend' => [
        'marekskopal/mcp-server-oauth' => [
            'target' => OAuthMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
            'before' => [
                'typo3/cms-backend/backend-module-validator',
            ],
        ],
    ],
];
