<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

// A two-sheet FlexForm for the pi_flexform-as-object test (#40). v14 takes the data structure as a string;
// v13 wants it keyed by the pointer value, "default" without a ds_pointerField.
$settingsDataStructure = '<T3DataStructure><sheets>'
    . '<sDEF><ROOT><sheetTitle>General</sheetTitle><type>array</type><el>'
    . '<settings.color><label>Color</label><config><type>input</type></config></settings.color>'
    . '</el></ROOT></sDEF>'
    . '<options><ROOT><sheetTitle>Options</sheetTitle><type>array</type><el>'
    . '<settings.size><label>Size</label><config><type>input</type></config></settings.size>'
    . '</el></ROOT></options>'
    . '</sheets></T3DataStructure>';

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
        '0' => ['showitem' => 'title, groups, partners, settings, members, hidden'],
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
        'settings' => [
            'label' => 'Settings',
            'config' => [
                'type' => 'flex',
                'ds' => (new Typo3Version())->getMajorVersion() >= 14
                    ? $settingsDataStructure
                    : ['default' => $settingsDataStructure],
            ],
        ],
        'members' => [
            'label' => 'Members',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_mcpmmfixture_group',
                'foreign_field' => 'parent_team',
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
