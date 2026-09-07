<?php

declare(strict_types=1);

/**
 * Mirrors the shape that motivated MM support: a team assigned to groups through a
 * `select` / `selectCheckBox` column with an MM table (ms_darts' tx_msdarts_domain_model_team.groups),
 * plus a `group` MM column pointing back at the same table so both relation types are exercised.
 */
return [
    'ctrl' => [
        'title' => 'MM fixture team',
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
        '0' => ['showitem' => 'title, groups, partners, hidden'],
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
        'groups' => [
            'label' => 'Groups',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectCheckBox',
                'foreign_table' => 'tx_mcpmmfixture_group',
                'MM' => 'tx_mcpmmfixture_team_group_mm',
                'minitems' => 0,
                'maxitems' => 10,
            ],
        ],
        'partners' => [
            'label' => 'Partner teams',
            'config' => [
                'type' => 'group',
                'allowed' => 'tx_mcpmmfixture_team',
                'MM' => 'tx_mcpmmfixture_team_partner_mm',
                'maxitems' => 10,
            ],
        ],
    ],
];
