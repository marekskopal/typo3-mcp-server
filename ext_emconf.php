<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'MCP Server',
    'description' => 'MCP server for TYPO3 CMS administration',
    'category' => 'module',
    'author' => 'Marek Skopal',
    'author_email' => 'skopal.marek@gmail.com',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
