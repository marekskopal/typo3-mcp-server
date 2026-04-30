<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(WorkspaceContextService::class)]
final class WorkspaceContextServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $tcaBackup = [];

    /** @var array<string, mixed> */
    private array $beUserBackup = [];

    protected function setUp(): void
    {
        $this->tcaBackup = $GLOBALS['TCA'] ?? [];
        $this->beUserBackup = isset($GLOBALS['BE_USER']) ? ['BE_USER' => $GLOBALS['BE_USER']] : [];
        unset($GLOBALS['BE_USER']);
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        if ($this->beUserBackup !== []) {
            $GLOBALS['BE_USER'] = $this->beUserBackup['BE_USER'];
        } else {
            unset($GLOBALS['BE_USER']);
        }
    }

    public function testGetCurrentWorkspaceIdReturnsZeroWithoutBackendUser(): void
    {
        $service = new WorkspaceContextService();

        self::assertSame(0, $service->getCurrentWorkspaceId());
        self::assertTrue($service->isLive());
    }

    public function testGetCurrentWorkspaceIdReadsBackendUserWorkspace(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->workspace = 5;
        $GLOBALS['BE_USER'] = $beUser;

        $service = new WorkspaceContextService();

        self::assertSame(5, $service->getCurrentWorkspaceId());
        self::assertFalse($service->isLive());
    }

    public function testIsTableWorkspaceAwareReadsTcaCtrl(): void
    {
        $GLOBALS['TCA'] = [
            'pages' => ['ctrl' => ['versioningWS' => true]],
            'sys_workspace' => ['ctrl' => []],
            'tx_other' => [],
        ];

        $service = new WorkspaceContextService();

        self::assertTrue($service->isTableWorkspaceAware('pages'));
        self::assertFalse($service->isTableWorkspaceAware('sys_workspace'));
        self::assertFalse($service->isTableWorkspaceAware('tx_other'));
        self::assertFalse($service->isTableWorkspaceAware('unknown_table'));
    }

    public function testApplyRestrictionIsNoOpForNonWorkspaceTable(): void
    {
        $GLOBALS['TCA'] = ['tx_other' => ['ctrl' => []]];

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->expects(self::never())->method('add');

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);

        $service = new WorkspaceContextService();
        $service->applyRestriction($queryBuilder, 'tx_other');
    }

    public function testOverlayReturnsRowUnchangedInLiveContext(): void
    {
        $GLOBALS['TCA'] = ['pages' => ['ctrl' => ['versioningWS' => true]]];

        $service = new WorkspaceContextService();
        $row = ['uid' => 42, 'title' => 'live'];

        self::assertSame($row, $service->overlay('pages', $row));
    }

    public function testOverlayReturnsRowUnchangedWhenTableNotWorkspaceAware(): void
    {
        $GLOBALS['TCA'] = ['tx_other' => ['ctrl' => []]];

        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->workspace = 5;
        $GLOBALS['BE_USER'] = $beUser;

        $service = new WorkspaceContextService();
        $row = ['uid' => 42, 'title' => 'live'];

        self::assertSame($row, $service->overlay('tx_other', $row));
    }

    public function testOverlayManyReturnsRowsUnchangedInLiveContext(): void
    {
        $GLOBALS['TCA'] = ['pages' => ['ctrl' => ['versioningWS' => true]]];

        $service = new WorkspaceContextService();
        $rows = [['uid' => 1], ['uid' => 2]];

        self::assertSame($rows, $service->overlayMany('pages', $rows));
    }
}
