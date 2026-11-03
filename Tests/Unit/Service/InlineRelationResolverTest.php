<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\InlineRelationResolver;
use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Service\WorkspaceContextService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(InlineRelationResolver::class)]
final class InlineRelationResolverTest extends TestCase
{
    private const array ANSWERS_CONFIG = [
        'type' => 'inline',
        'foreign_table' => 'tx_faq_answer',
        'foreign_field' => 'question',
        'foreign_sortby' => 'sorting',
    ];

    protected function setUp(): void
    {
        $GLOBALS['TCA']['tx_faq_question'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'answers' => ['config' => self::ANSWERS_CONFIG],
                'image' => ['config' => ['type' => 'file']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TCA']);
    }

    public function testListsTheChildUidsInTheOrderRelationHandlerReturns(): void
    {
        $relationHandler = $this->createMock(RelationHandler::class);
        $relationHandler->expects(self::once())
            ->method('start')
            ->with('2', 'tx_faq_answer', '', 7, 'tx_faq_question', self::ANSWERS_CONFIG);
        $relationHandler->itemArray = [['id' => 12, 'table' => 'tx_faq_answer'], ['id' => '11', 'table' => 'tx_faq_answer']];
        GeneralUtility::addInstance(RelationHandler::class, $relationHandler);

        $children = $this->createResolver()->childUids('tx_faq_question', ['uid' => 7, 'answers' => 2]);

        self::assertSame(['answers' => [12, 11]], $children);
    }

    public function testReadsAWorkspaceVersionUnderItsOwnUid(): void
    {
        $relationHandler = $this->createMock(RelationHandler::class);
        $relationHandler->expects(self::once())->method('start')->with(self::anything(), self::anything(), self::anything(), 70);
        GeneralUtility::addInstance(RelationHandler::class, $relationHandler);

        $this->createResolver()->childUids('tx_faq_question', ['uid' => 7, '_ORIG_uid' => 70]);
    }

    public function testLeavesOutChildrenInATableTheUserMayNotRead(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(false);

        $resolver = new InlineRelationResolver(new TcaSchemaService(), new WorkspaceContextService(), $permissionService);

        self::assertSame([], $resolver->childUids('tx_faq_question', ['uid' => 7]));
    }

    public function testTableWithoutInlineFieldsResolvesNothing(): void
    {
        $GLOBALS['TCA']['tx_plain'] = ['ctrl' => [], 'columns' => ['title' => ['config' => ['type' => 'input']]]];

        self::assertSame([], $this->createResolver()->childUids('tx_plain', ['uid' => 7]));
    }

    private function createResolver(): InlineRelationResolver
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(true);

        return new InlineRelationResolver(new TcaSchemaService(), new WorkspaceContextService(), $permissionService);
    }
}
