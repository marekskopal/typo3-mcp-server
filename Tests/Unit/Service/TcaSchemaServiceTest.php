<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TcaSchemaService::class)]
final class TcaSchemaServiceTest extends TestCase
{
    private TcaSchemaService $service;

    protected function setUp(): void
    {
        $this->service = new TcaSchemaService();
        $GLOBALS['TCA'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
    }

    public function testGetListFieldsReturnsUidPidWhenTableNotInTca(): void
    {
        self::assertSame(['uid', 'pid'], $this->service->getListFields('nonexistent_table'));
    }

    public function testGetListFieldsIncludesLabelField(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => ['label' => 'title'],
            'columns' => [],
        ];

        self::assertSame(['uid', 'pid', 'title'], $this->service->getListFields('tx_test'));
    }

    public function testGetListFieldsIncludesLabelAltFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [
                'label' => 'title',
                'label_alt' => 'subtitle, description',
            ],
            'columns' => [],
        ];

        self::assertSame(['uid', 'pid', 'title', 'subtitle', 'description'], $this->service->getListFields('tx_test'));
    }

    public function testGetListFieldsIncludesHiddenFromEnableColumns(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [
                'label' => 'title',
                'enablecolumns' => ['disabled' => 'hidden'],
            ],
            'columns' => [],
        ];

        self::assertSame(['uid', 'pid', 'title', 'hidden'], $this->service->getListFields('tx_test'));
    }

    public function testGetListFieldsDeduplicates(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [
                'label' => 'title',
                'label_alt' => 'title',
            ],
            'columns' => [],
        ];

        self::assertSame(['uid', 'pid', 'title'], $this->service->getListFields('tx_test'));
    }

    public function testGetReadFieldsIncludesValueTypes(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'body' => ['config' => ['type' => 'text']],
                'count' => ['config' => ['type' => 'number']],
                'date' => ['config' => ['type' => 'datetime']],
                'mail' => ['config' => ['type' => 'email']],
                'url' => ['config' => ['type' => 'link']],
                'hex' => ['config' => ['type' => 'color']],
                'path' => ['config' => ['type' => 'slug']],
                'active' => ['config' => ['type' => 'check']],
                'status' => ['config' => ['type' => 'radio']],
                'data' => ['config' => ['type' => 'json']],
                'identifier' => ['config' => ['type' => 'uuid']],
                'locale' => ['config' => ['type' => 'country']],
            ],
        ];

        $fields = $this->service->getReadFields('tx_test');

        self::assertContains('title', $fields);
        self::assertContains('body', $fields);
        self::assertContains('count', $fields);
        self::assertContains('date', $fields);
        self::assertContains('mail', $fields);
        self::assertContains('url', $fields);
        self::assertContains('hex', $fields);
        self::assertContains('path', $fields);
        self::assertContains('active', $fields);
        self::assertContains('status', $fields);
        self::assertContains('data', $fields);
        self::assertContains('identifier', $fields);
        self::assertContains('locale', $fields);
        self::assertContains('uid', $fields);
        self::assertContains('pid', $fields);
    }

    public function testGetReadFieldsExcludesRelationTypes(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'children' => ['config' => ['type' => 'inline']],
                'image' => ['config' => ['type' => 'file']],
                'docs' => ['config' => ['type' => 'folder']],
                'cats' => ['config' => ['type' => 'category']],
                'layout' => ['config' => ['type' => 'flex']],
                'crop' => ['config' => ['type' => 'imageManipulation']],
                'virtual' => ['config' => ['type' => 'none']],
                'hidden_data' => ['config' => ['type' => 'passthrough']],
                'custom' => ['config' => ['type' => 'user']],
            ],
        ];

        $fields = $this->service->getReadFields('tx_test');

        self::assertContains('title', $fields);
        self::assertNotContains('children', $fields);
        self::assertNotContains('image', $fields);
        self::assertNotContains('docs', $fields);
        self::assertNotContains('cats', $fields);
        self::assertNotContains('layout', $fields);
        self::assertNotContains('crop', $fields);
        self::assertNotContains('virtual', $fields);
        self::assertNotContains('hidden_data', $fields);
        self::assertNotContains('custom', $fields);
    }

    public function testGetReadFieldsIncludesSelectWithoutMM(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'type' => ['config' => ['type' => 'select', 'items' => []]],
            ],
        ];

        self::assertContains('type', $this->service->getReadFields('tx_test'));
    }

    public function testGetReadFieldsExcludesSelectWithMM(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'categories' => ['config' => ['type' => 'select', 'MM' => 'tx_test_category_mm']],
            ],
        ];

        self::assertNotContains('categories', $this->service->getReadFields('tx_test'));
    }

    public function testGetReadFieldsIncludesGroupWithoutMM(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'related' => ['config' => ['type' => 'group']],
            ],
        ];

        self::assertContains('related', $this->service->getReadFields('tx_test'));
    }

    public function testGetReadFieldsExcludesGroupWithMM(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'related' => ['config' => ['type' => 'group', 'MM' => 'tx_test_related_mm']],
            ],
        ];

        self::assertNotContains('related', $this->service->getReadFields('tx_test'));
    }

    public function testGetReadFieldsExcludesSystemFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [
                'tstamp' => 'tstamp',
                'crdate' => 'crdate',
                'delete' => 'deleted',
                'sortby' => 'sorting',
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
                'enablecolumns' => [
                    'disabled' => 'hidden',
                    'starttime' => 'starttime',
                    'endtime' => 'endtime',
                    'fe_group' => 'fe_group',
                ],
            ],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'tstamp' => ['config' => ['type' => 'number']],
                'crdate' => ['config' => ['type' => 'number']],
                'deleted' => ['config' => ['type' => 'check']],
                'sorting' => ['config' => ['type' => 'number']],
                'sys_language_uid' => ['config' => ['type' => 'number']],
                'l10n_parent' => ['config' => ['type' => 'number']],
                'hidden' => ['config' => ['type' => 'check']],
                'starttime' => ['config' => ['type' => 'datetime']],
                'endtime' => ['config' => ['type' => 'datetime']],
                'fe_group' => ['config' => ['type' => 'select']],
                'l10n_diffsource' => ['config' => ['type' => 'passthrough']],
            ],
        ];

        $fields = $this->service->getReadFields('tx_test');

        self::assertContains('title', $fields);
        self::assertNotContains('tstamp', $fields);
        self::assertNotContains('crdate', $fields);
        self::assertNotContains('deleted', $fields);
        self::assertNotContains('sorting', $fields);
        self::assertNotContains('sys_language_uid', $fields);
        self::assertNotContains('l10n_parent', $fields);
        self::assertNotContains('hidden', $fields);
        self::assertNotContains('starttime', $fields);
        self::assertNotContains('endtime', $fields);
        self::assertNotContains('fe_group', $fields);
        self::assertNotContains('l10n_diffsource', $fields);
    }

    public function testGetWritableFieldsExcludesReadOnlyFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'slug' => ['config' => ['type' => 'slug', 'readOnly' => true]],
            ],
        ];

        $fields = $this->service->getWritableFields('tx_test');

        self::assertContains('title', $fields);
        self::assertNotContains('slug', $fields);
    }

    public function testGetWritableFieldsReturnsEmptyForMissingTable(): void
    {
        self::assertSame([], $this->service->getWritableFields('nonexistent_table'));
    }

    public function testGetReadFieldsReturnsUidPidForMissingTable(): void
    {
        self::assertSame(['uid', 'pid'], $this->service->getReadFields('nonexistent_table'));
    }
}
