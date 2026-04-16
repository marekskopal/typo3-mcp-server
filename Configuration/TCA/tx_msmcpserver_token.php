<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_token',
        'label' => 'name',
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
        'name' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_token.name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'token_hash' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_token.token_hash',
            'config' => [
                'type' => 'input',
                'size' => 64,
                'max' => 64,
                'readOnly' => true,
            ],
        ],
        'be_user' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_token.be_user',
            'config' => [
                'type' => 'group',
                'allowed' => 'be_users',
                'maxitems' => 1,
                'size' => 1,
                'required' => true,
            ],
        ],
        'expires' => [
            'label' => 'LLL:EXT:ms_mcp_server/Resources/Private/Language/locallang.xlf:tx_msmcpserver_token.expires',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
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
            'showitem' => 'name, token_hash, be_user, expires, hidden',
        ],
    ],
];
