<?php

declare(strict_types=1);

use MarekSkopal\MsMcpServer\Controller\TokenManagementController;

return [
    'msmcpserver_tokens' => [
        'parent' => 'system',
        'position' => [],
        'access' => 'admin',
        'iconIdentifier' => 'module-mcp-server',
        'labels' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:module',
        'routes' => [
            '_default' => [
                'target' => TokenManagementController::class . '::indexAction',
            ],
            'create' => [
                'target' => TokenManagementController::class . '::createAction',
                'methods' => ['POST'],
            ],
            'delete' => [
                'target' => TokenManagementController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
