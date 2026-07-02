<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\DataHandling;

use MarekSkopal\MsMcpServer\DataHandling\BackendUserPasswordChangeHook;
use MarekSkopal\MsMcpServer\OAuth\AuthorizationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;

#[CoversClass(BackendUserPasswordChangeHook::class)]
final class BackendUserPasswordChangeHookTest extends TestCase
{
    public function testRevokesAuthorizationsWhenPasswordChanged(): void
    {
        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::once())->method('revokeByBackendUser')->with(7);

        $hook = new BackendUserPasswordChangeHook($authorizationService);
        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'be_users',
            7,
            ['password' => 'new-hash'],
            $this->createStub(DataHandler::class),
        );
    }

    public function testIgnoresUpdatesWithoutPasswordField(): void
    {
        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::never())->method('revokeByBackendUser');

        $hook = new BackendUserPasswordChangeHook($authorizationService);
        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'be_users',
            7,
            ['email' => 'a@example.com'],
            $this->createStub(DataHandler::class),
        );
    }

    public function testIgnoresOtherTables(): void
    {
        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::never())->method('revokeByBackendUser');

        $hook = new BackendUserPasswordChangeHook($authorizationService);
        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'fe_users',
            7,
            ['password' => 'new-hash'],
            $this->createStub(DataHandler::class),
        );
    }

    public function testIgnoresNewRecords(): void
    {
        $authorizationService = $this->createMock(AuthorizationService::class);
        $authorizationService->expects(self::never())->method('revokeByBackendUser');

        $hook = new BackendUserPasswordChangeHook($authorizationService);
        $hook->processDatamap_afterDatabaseOperations(
            'new',
            'be_users',
            'NEW123abc',
            ['password' => 'new-hash'],
            $this->createStub(DataHandler::class),
        );
    }
}
