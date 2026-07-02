<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\DataHandling;

use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Revokes a backend user's MCP authorizations when their password is changed, so that tokens issued
 * before a credential reset (e.g. after a compromise) can no longer be used. Access-token/refresh
 * validation only re-checks the be_users disable/deleted/starttime/endtime flags, not the password,
 * so without this a password change alone would not cut off existing MCP access.
 */
readonly class BackendUserPasswordChangeHook
{
    public function __construct(private AuthorizationService $authorizationService)
    {
    }

    // The method name is dictated by TYPO3's DataHandler hook dispatcher, so it cannot be camelCase.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    /** @param array<string, mixed> $fieldArray */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($status !== 'update' || $table !== 'be_users' || !array_key_exists('password', $fieldArray)) {
            return;
        }

        $uid = (int) $id;
        if ($uid > 0) {
            $this->authorizationService->revokeByBackendUser($uid);
        }
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
}
