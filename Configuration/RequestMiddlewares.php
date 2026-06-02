<?php

declare(strict_types=1);

return [
    'frontend' => [
        'marekskopal/mcp-server-oauth' => [
            'target' => \MarekSkopal\MsMcpServer\Middleware\OAuthMiddleware::class,
            'before' => [
                'marekskopal/mcp-server',
            ],
            'after' => [
                'typo3/cms-frontend/normalize-params',
            ],
        ],
        'marekskopal/mcp-server' => [
            'target' => \MarekSkopal\MsMcpServer\Middleware\McpServerMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'marekskopal/mcp-server-oauth',
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
            'target' => \MarekSkopal\MsMcpServer\Middleware\OAuthMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
            'before' => [
                'typo3/cms-backend/backend-module-validator',
            ],
        ],
    ],
];
