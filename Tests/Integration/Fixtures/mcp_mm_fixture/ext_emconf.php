<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'MCP MM Fixture',
    'description' => 'Integration-test fixture for the TYPO3 MCP Server: a team table with select and group MM relations.',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
