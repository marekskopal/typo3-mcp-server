<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_oauth_client',
        'label' => 'client_name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:ms_mcp_server/Resources/Public/Icons/Extension.svg',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'client_id' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_oauth_client.client_id',
            'config' => [
                'type' => 'input',
                'size' => 64,
                'max' => 128,
                'readOnly' => true,
            ],
        ],
        'client_name' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_oauth_client.client_name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'redirect_uris' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_oauth_client.redirect_uris',
            'config' => [
                'type' => 'text',
                'rows' => 5,
                'cols' => 50,
                'required' => true,
            ],
        ],
        'be_user' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_oauth_client.be_user',
            'config' => [
                'type' => 'group',
                'allowed' => 'be_users',
                'maxitems' => 1,
                'size' => 1,
            ],
        ],
        'hidden' => [
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'default' => 0,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'client_name, client_id, redirect_uris, be_user, hidden',
        ],
    ],
];
