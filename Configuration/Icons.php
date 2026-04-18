<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-mcp-server' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ms_mcp_server/Resources/Public/Icons/Extension.svg',
    ],
];
