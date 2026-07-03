<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\EventListener;

use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use TYPO3\CMS\Backend\Authentication\Event\PasswordHasBeenResetEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Revokes a backend user's MCP authorizations when their password is reset via TYPO3's
 * PasswordReset service (the "forgot password" email link and the backend:resetpassword
 * CLI command). That flow updates be_users directly with a query builder and never goes
 * through DataHandler, so BackendUserPasswordChangeHook does not fire for it — yet it is
 * the canonical response to a credential compromise, exactly when issued tokens must die.
 *
 * PasswordHasBeenResetEvent exists only in TYPO3 v14+. On v13 the class is never dispatched
 * (registration by class-name string is harmless), so this flow is uncovered there: after an
 * email password reset on v13, additionally disable the user or revoke their tokens in the
 * backend module. DataHandler-mediated password changes are covered on both versions.
 */
#[AsEventListener(identifier: 'ms-mcp-server/password-reset-token-revocation')]
readonly class PasswordResetTokenRevocationListener
{
    public function __construct(private AuthorizationService $authorizationService)
    {
    }

    public function __invoke(PasswordHasBeenResetEvent $event): void
    {
        if ($event->userId > 0) {
            $this->authorizationService->revokeByBackendUser($event->userId);
        }
    }
}
