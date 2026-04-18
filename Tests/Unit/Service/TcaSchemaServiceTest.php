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

    public function testGetFileFieldsReturnsFileTypeFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'image' => ['config' => ['type' => 'file']],
                'media' => ['config' => ['type' => 'file', 'allowed' => 'common-image-types']],
            ],
        ];

        $fields = $this->service->getFileFields('tx_test');

        self::assertContains('image', $fields);
        self::assertContains('media', $fields);
        self::assertNotContains('title', $fields);
    }

    public function testGetFileFieldsReturnsLegacyInlineFileReferenceFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'image' => ['config' => ['type' => 'inline', 'foreign_table' => 'sys_file_reference']],
                'children' => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_test_child']],
            ],
        ];

        $fields = $this->service->getFileFields('tx_test');

        self::assertContains('image', $fields);
        self::assertNotContains('children', $fields);
    }

    public function testGetFileFieldsExcludesNonFileFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'body' => ['config' => ['type' => 'text']],
                'category' => ['config' => ['type' => 'category']],
                'tags' => ['config' => ['type' => 'select', 'foreign_table' => 'tx_test_tag']],
            ],
        ];

        self::assertSame([], $this->service->getFileFields('tx_test'));
    }

    public function testGetFileFieldsReturnsEmptyForMissingTable(): void
    {
        self::assertSame([], $this->service->getFileFields('nonexistent_table'));
    }

    public function testGetFieldsSchemaReturnsEmptyForMissingTable(): void
    {
        $result = $this->service->getFieldsSchema('nonexistent_table');

        self::assertSame('nonexistent_table', $result['table']);
        self::assertSame([], $result['fields']);
    }

    public function testGetFieldsSchemaReturnsFieldTypeAndLabel(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => [
                    'label' => 'Title',
                    'config' => ['type' => 'input', 'required' => true, 'max' => 255],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');

        self::assertSame('tx_test', $result['table']);
        self::assertCount(1, $result['fields']);

        $field = $result['fields'][0];
        self::assertSame('title', $field['name']);
        self::assertSame('input', $field['type']);
        self::assertSame('Title', $field['label']);
        self::assertTrue($field['required']);
        self::assertSame(255, $field['max']);
    }

    public function testGetFieldsSchemaReturnsSelectItems(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'status' => [
                    'label' => 'Status',
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'items' => [
                            ['label' => 'Draft', 'value' => 0],
                            ['label' => 'Published', 'value' => 1],
                            ['label' => 'Archived', 'value' => 2],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('select', $field['type']);
        self::assertSame('selectSingle', $field['renderType']);
        self::assertCount(3, $field['items']);
        self::assertSame(0, $field['items'][0]['value']);
        self::assertSame('Draft', $field['items'][0]['label']);
        self::assertSame(1, $field['items'][1]['value']);
        self::assertSame('Published', $field['items'][1]['label']);
    }

    public function testGetFieldsSchemaReturnsRadioItems(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'layout' => [
                    'label' => 'Layout',
                    'config' => [
                        'type' => 'radio',
                        'items' => [
                            ['label' => 'Default', 'value' => 0],
                            ['label' => 'Sidebar', 'value' => 1],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('radio', $field['type']);
        self::assertCount(2, $field['items']);
        self::assertSame('Default', $field['items'][0]['label']);
    }

    public function testGetFieldsSchemaReturnsConstraints(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'price' => [
                    'label' => 'Price',
                    'config' => [
                        'type' => 'number',
                        'required' => true,
                        'default' => 0,
                        'range' => ['lower' => 0, 'upper' => 99999],
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('number', $field['type']);
        self::assertTrue($field['required']);
        self::assertSame(0, $field['default']);
        self::assertSame(['lower' => 0, 'upper' => 99999], $field['range']);
    }

    public function testGetFieldsSchemaReturnsSlugGeneratorOptions(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'slug' => [
                    'label' => 'URL Segment',
                    'config' => [
                        'type' => 'slug',
                        'generatorOptions' => [
                            'fields' => ['title'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('slug', $field['type']);
        self::assertSame(['title'], $field['generatedFrom']);
    }

    public function testGetFieldsSchemaReturnsReadOnlyFlag(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'slug' => [
                    'label' => 'Slug',
                    'config' => ['type' => 'slug', 'readOnly' => true],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertTrue($field['readOnly']);
    }

    public function testGetFieldsSchemaReturnsSelectWithForeignTable(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'category' => [
                    'label' => 'Category',
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectSingle',
                        'foreign_table' => 'sys_category',
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('select', $field['type']);
        self::assertSame('sys_category', $field['foreignTable']);
    }

    public function testGetFieldsSchemaExcludesSystemFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [
                'tstamp' => 'tstamp',
                'crdate' => 'crdate',
            ],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'tstamp' => ['config' => ['type' => 'number']],
                'crdate' => ['config' => ['type' => 'number']],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');

        self::assertCount(1, $result['fields']);
        self::assertSame('title', $result['fields'][0]['name']);
    }

    public function testGetFieldsSchemaReturnsEvalAndPlaceholder(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'identifier' => [
                    'label' => 'Identifier',
                    'config' => [
                        'type' => 'input',
                        'eval' => 'trim,uniqueInPid',
                        'placeholder' => 'Enter unique identifier',
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('trim,uniqueInPid', $field['eval']);
        self::assertSame('Enter unique identifier', $field['placeholder']);
    }

    public function testGetFieldsSchemaReturnsCheckboxItems(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'options' => [
                    'label' => 'Options',
                    'config' => [
                        'type' => 'check',
                        'items' => [
                            ['label' => 'Option A'],
                            ['label' => 'Option B'],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('check', $field['type']);
        self::assertSame(['Option A', 'Option B'], $field['items']);
    }

    public function testGetFieldsSchemaReturnsDatetimeFormat(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'event_date' => [
                    'label' => 'Event Date',
                    'config' => [
                        'type' => 'datetime',
                        'format' => 'date',
                        'dbType' => 'date',
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('datetime', $field['type']);
        self::assertSame('date', $field['format']);
        self::assertSame('date', $field['dbType']);
    }

    public function testGetFieldsSchemaReturnsLinkAllowedTypes(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'url' => [
                    'label' => 'URL',
                    'config' => [
                        'type' => 'link',
                        'allowedTypes' => ['url', 'email', 'page'],
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test');
        $field = $result['fields'][0];

        self::assertSame('link', $field['type']);
        self::assertSame(['url', 'email', 'page'], $field['allowedTypes']);
    }
}
