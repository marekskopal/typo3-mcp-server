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
];
