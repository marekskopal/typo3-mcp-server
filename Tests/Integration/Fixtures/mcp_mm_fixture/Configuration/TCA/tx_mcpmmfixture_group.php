<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'MM fixture group',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, hidden'],
    ],
    'columns' => [
        'hidden' => [
            'label' => 'Hidden',
            'config' => ['type' => 'check'],
        ],
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input', 'size' => 30, 'max' => 255, 'required' => true],
        ],
        // The team a group belongs to as an inline child (tx_mcpmmfixture_team.members), for #41.
        'parent_team' => [
            'label' => 'Parent team',
            'config' => ['type' => 'number', 'default' => 0],
        ],
    ],
];
