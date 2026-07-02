<?php

declare(strict_types=1);

// Revoke a backend user's MCP tokens when their password changes (credential-reset response).
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \MarekSkopal\MsMcpServer\DataHandling\BackendUserPasswordChangeHook::class;

$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ms_mcp_server']['tables']['tx_news_domain_model_news'] = [
    'label' => 'News',
    'prefix' => 'news',
];
