<?php

declare(strict_types=1);

return [
    'frontend' => [
        'marekskopal/mcp-server' => [
            'target' => \MarekSkopal\MsMcpServer\Middleware\McpServerMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/normalize-params',
            ],
        ],
    ],
];
