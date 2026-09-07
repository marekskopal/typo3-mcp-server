<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\MmRelationResolver;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(MmRelationResolver::class)]
final class MmRelationResolverTest extends TestCase
{
    private const array GROUPS_CONFIG = [
        'type' => 'select',
        'renderType' => 'selectCheckBox',
        'foreign_table' => 'tx_test_group',
        'MM' => 'tx_test_team_group_mm',
    ];

    private MmRelationResolver $resolver;

    protected function setUp(): void
    {
        $GLOBALS['TCA'] = [
            'tx_test_team' => [
                'ctrl' => [],
                'columns' => [
                    'title' => ['config' => ['type' => 'input']],
                    'groups' => ['config' => self::GROUPS_CONFIG],
                    'related' => ['config' => ['type' => 'group', 'allowed' => 'tt_content,pages', 'MM' => 'tx_test_team_related_mm']],
                ],
            ],
        ];
        unset($GLOBALS['BE_USER']);

        $this->resolver = new MmRelationResolver(new TcaSchemaService(), new WorkspaceContextService());
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TCA'], $GLOBALS['BE_USER']);
    }

    /**
     * No RelationHandler is registered here, so the real class would have to be constructed — and
     * that would fail outside a TYPO3 bootstrap. Passing proves no MM query is attempted.
     */
    public function testResolveLeavesRowUntouchedWhenNoMMFieldIsSelected(): void
    {
        $row = ['uid' => 5, 'title' => 'Team', 'groups' => 2];

        self::assertSame($row, $this->resolver->resolve('tx_test_team', $row, ['uid', 'title']));
        self::assertSame([$row], $this->resolver->resolveMany('tx_test_team', [$row], ['uid', 'title']));
    }

    public function testResolveLeavesRowUntouchedForTableWithoutMMFields(): void
    {
        $row = ['uid' => 5, 'header' => 'Text'];

        self::assertSame($row, $this->resolver->resolve('tt_content', $row, ['uid', 'header']));
        self::assertSame([], $this->resolver->selectedMMFields('tt_content', ['uid', 'header']));
    }

    public function testSelectedMMFieldsIntersectsWithTheSelection(): void
    {
        self::assertSame(['groups'], array_keys($this->resolver->selectedMMFields('tx_test_team', ['uid', 'groups', 'title'])));
        self::assertSame(['groups', 'related'], array_keys($this->resolver->selectedMMFields('tx_test_team', ['groups', 'related'])));
        self::assertTrue($this->resolver->isMMField('tx_test_team', 'groups'));
        self::assertFalse($this->resolver->isMMField('tx_test_team', 'title'));
    }

    /** The count in the physical column is replaced by the related UIDs, in MM sorting order. */
    public function testResolveReplacesCountWithRelatedUidsFromRelationHandler(): void
    {
        $relationHandler = $this->createMock(RelationHandler::class);
        $relationHandler->expects(self::once())->method('setWorkspaceId')->with(0);
        $relationHandler->expects(self::once())
            ->method('start')
            ->with('', 'tx_test_group', 'tx_test_team_group_mm', 5, 'tx_test_team', self::GROUPS_CONFIG);
        $relationHandler->expects(self::once())->method('processDeletePlaceholder');
        $relationHandler->itemArray = [
            ['table' => 'tx_test_group', 'id' => 21, 'sorting' => 1],
            ['table' => 'tx_test_group', 'id' => '20', 'sorting' => 2],
        ];
        GeneralUtility::addInstance(RelationHandler::class, $relationHandler);

        $row = $this->resolver->resolve('tx_test_team', ['uid' => 5, 'title' => 'Team', 'groups' => 2], ['uid', 'title', 'groups']);

        self::assertSame(['uid' => 5, 'title' => 'Team', 'groups' => [21, 20]], $row);
    }

    public function testResolveManyResolvesEveryRow(): void
    {
        foreach ([[['table' => 'tx_test_group', 'id' => 20]], []] as $items) {
            $relationHandler = $this->createStub(RelationHandler::class);
            $relationHandler->itemArray = $items;
            GeneralUtility::addInstance(RelationHandler::class, $relationHandler);
        }

        $rows = $this->resolver->resolveMany(
            'tx_test_team',
            [['uid' => 1, 'groups' => 1], ['uid' => 2, 'groups' => 0]],
            ['uid', 'groups'],
        );

        self::assertSame([['uid' => 1, 'groups' => [20]], ['uid' => 2, 'groups' => []]], $rows);
    }

    /**
     * In a workspace the overlaid row keeps the live uid in `uid` and carries the version's uid in
     * `_ORIG_uid`. DataHandler writes the version's MM rows under the version uid, so that is the
     * one the relation must be read for — the live uid would return the live set.
     */
    public function testResolveReadsTheVersionUidInAWorkspace(): void
    {
        $beUser = $this->createStub(BackendUserAuthentication::class);
        $beUser->workspace = 3;
        $GLOBALS['BE_USER'] = $beUser;

        $relationHandler = $this->createMock(RelationHandler::class);
        $relationHandler->expects(self::once())->method('setWorkspaceId')->with(3);
        $relationHandler->expects(self::once())
            ->method('start')
            ->with('', 'tx_test_group', 'tx_test_team_group_mm', 12, 'tx_test_team', self::GROUPS_CONFIG);
        $relationHandler->itemArray = [['table' => 'tx_test_group', 'id' => 20]];
        GeneralUtility::addInstance(RelationHandler::class, $relationHandler);

        $row = $this->resolver->resolve('tx_test_team', ['uid' => 5, '_ORIG_uid' => 12, 'groups' => 1], ['uid', 'groups']);

        self::assertSame([20], $row['groups']);
    }

    /** A group field allowing several tables cannot be reduced to bare integers without losing the table. */
    public function testResolveReturnsTablePrefixedIdsForMultiTableGroup(): void
    {
        $relationHandler = $this->createMock(RelationHandler::class);
        $relationHandler->expects(self::once())
            ->method('start')
            ->with('', 'tt_content,pages', 'tx_test_team_related_mm', 5, 'tx_test_team', self::anything());
        $relationHandler->itemArray = [
            ['table' => 'tt_content', 'id' => 12],
            ['table' => 'pages', 'id' => 3],
        ];
        GeneralUtility::addInstance(RelationHandler::class, $relationHandler);

        $row = $this->resolver->resolve('tx_test_team', ['uid' => 5, 'related' => 2], ['uid', 'related']);

        self::assertSame(['tt_content_12', 'pages_3'], $row['related']);
    }

    /** Without a uid there is nothing to look the relation up by; an empty list would read as "no relations". */
    public function testResolveThrowsForRowWithoutUid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1725900003);

        $this->resolver->resolve('tx_test_team', ['title' => 'Team', 'groups' => 2], ['title', 'groups']);
    }

    public function testResolveSkipsItemsWithoutNumericId(): void
    {
        $relationHandler = $this->createStub(RelationHandler::class);
        $relationHandler->itemArray = [
            ['table' => 'tx_test_group', 'id' => 20],
            ['table' => 'tx_test_group', 'id' => 'NEWabc'],
            ['table' => 'tx_test_group'],
        ];
        GeneralUtility::addInstance(RelationHandler::class, $relationHandler);

        $row = $this->resolver->resolve('tx_test_team', ['uid' => 5, 'groups' => 3], ['uid', 'groups']);

        self::assertSame([20], $row['groups']);
    }
}
