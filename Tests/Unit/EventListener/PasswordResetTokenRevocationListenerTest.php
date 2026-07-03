<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\EventListener;

use MarekSkopal\MsMcpServer\EventListener\PasswordResetTokenRevocationListener;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Authentication\Event\PasswordHasBeenResetEvent;

#[CoversClass(PasswordResetTokenRevocationListener::class)]
final class PasswordResetTokenRevocationListenerTest extends TestCase
{
    protected function setUp(): void
    {
        if (class_exists(PasswordHasBeenResetEvent::class)) {
            return;
        }

        self::markTestSkipped('PasswordHasBeenResetEvent does not exist in this TYPO3 version (introduced in v14).');
    }

    public function testRevokesAuthorizationsForResetUser(): void
    {
        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::once())
            ->method('revokeByBackendUser')
            ->with(42);

        $listener = new PasswordResetTokenRevocationListener($authorizationService);
        $listener(new PasswordHasBeenResetEvent(42));
    }

    public function testIgnoresInvalidUserId(): void
    {
        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::never())
            ->method('revokeByBackendUser');

        $listener = new PasswordResetTokenRevocationListener($authorizationService);
        $listener(new PasswordHasBeenResetEvent(0));
    }
}
