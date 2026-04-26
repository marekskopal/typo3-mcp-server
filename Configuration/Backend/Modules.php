<?php

declare(strict_types=1);

use MarekSkopal\MsMcpServer\Controller\ExtensionTableController;
use MarekSkopal\MsMcpServer\Controller\OAuthClientController;

return [
    'msmcpserver_oauth_clients' => [
        'parent' => 'system',
        'position' => [],
        'access' => 'admin',
        'iconIdentifier' => 'module-mcp-server',
        'labels' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => OAuthClientController::class . '::indexAction',
            ],
            'create' => [
                'target' => OAuthClientController::class . '::createAction',
                'methods' => ['POST'],
            ],
            'edit' => [
                'target' => OAuthClientController::class . '::editAction',
            ],
            'update' => [
                'target' => OAuthClientController::class . '::updateAction',
                'methods' => ['POST'],
            ],
            'delete' => [
                'target' => OAuthClientController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
            'revoke_token' => [
                'target' => OAuthClientController::class . '::revokeTokenAction',
                'methods' => ['POST'],
            ],
            'extensions' => [
                'target' => ExtensionTableController::class . '::indexAction',
            ],
            'discover' => [
                'target' => ExtensionTableController::class . '::discoverAction',
                'methods' => ['POST'],
            ],
            'toggle' => [
                'target' => ExtensionTableController::class . '::toggleAction',
                'methods' => ['POST'],
            ],
            'edit_extension' => [
                'target' => ExtensionTableController::class . '::editAction',
            ],
            'update_extension' => [
                'target' => ExtensionTableController::class . '::updateAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
