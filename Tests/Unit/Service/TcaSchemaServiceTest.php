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

    public function testGetTranslationConfigReturnsFieldNames(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
                'translationSource' => 'l10n_source',
            ],
            'columns' => [],
        ];

        $result = $this->service->getTranslationConfig('tx_test');

        self::assertSame('sys_language_uid', $result['languageField']);
        self::assertSame('l10n_parent', $result['transOrigPointerField']);
        self::assertSame('l10n_source', $result['translationSource']);
    }

    public function testGetTranslationConfigReturnsNullsForNonTranslatableTable(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [],
        ];

        $result = $this->service->getTranslationConfig('tx_test');

        self::assertNull($result['languageField']);
        self::assertNull($result['transOrigPointerField']);
        self::assertNull($result['translationSource']);
    }

    public function testGetTranslationConfigReturnsNullsForMissingTable(): void
    {
        $result = $this->service->getTranslationConfig('nonexistent_table');

        self::assertNull($result['languageField']);
        self::assertNull($result['transOrigPointerField']);
        self::assertNull($result['translationSource']);
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
                'crop' => ['config' => ['type' => 'imageManipulation']],
                'virtual' => ['config' => ['type' => 'none']],
                'custom' => ['config' => ['type' => 'user']],
            ],
        ];

        $fields = $this->service->getReadFields('tx_test');

        self::assertContains('title', $fields);
        self::assertNotContains('children', $fields);
        self::assertNotContains('image', $fields);
        self::assertNotContains('docs', $fields);
        self::assertNotContains('cats', $fields);
        self::assertNotContains('crop', $fields);
        self::assertNotContains('virtual', $fields);
        self::assertNotContains('custom', $fields);
    }

    public function testGetReadFieldsIncludesFlexType(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'pi_flexform' => ['config' => ['type' => 'flex']],
            ],
        ];

        self::assertContains('pi_flexform', $this->service->getReadFields('tx_test'));
    }

    public function testGetWritableFieldsIncludesFlexType(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'pi_flexform' => ['config' => ['type' => 'flex']],
            ],
        ];

        self::assertContains('pi_flexform', $this->service->getWritableFields('tx_test'));
    }

    public function testGetReadFieldsIncludesPassthroughType(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'plan' => ['config' => ['type' => 'passthrough']],
            ],
        ];

        self::assertContains('plan', $this->service->getReadFields('tx_test'));
    }

    public function testGetWritableFieldsIncludesPassthroughType(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'plan' => ['config' => ['type' => 'passthrough']],
            ],
        ];

        self::assertContains('plan', $this->service->getWritableFields('tx_test'));
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

    public function testGetReadFieldsIncludesSelectWithMM(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'categories' => ['config' => ['type' => 'select', 'foreign_table' => 'tx_test_category', 'MM' => 'tx_test_category_mm']],
            ],
        ];

        self::assertContains('categories', $this->service->getReadFields('tx_test'));
        self::assertContains('categories', $this->service->getWritableFields('tx_test'));
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

    public function testGetReadFieldsIncludesGroupWithMM(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'related' => ['config' => ['type' => 'group', 'allowed' => 'tx_test', 'MM' => 'tx_test_related_mm']],
            ],
        ];

        self::assertContains('related', $this->service->getReadFields('tx_test'));
        self::assertContains('related', $this->service->getWritableFields('tx_test'));
    }

    public function testGetWritableFieldsExcludesReadOnlyMMField(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'related_from' => ['config' => ['type' => 'group', 'allowed' => 'tx_test', 'MM' => 'tx_test_related_mm', 'MM_opposite_field' => 'related', 'readOnly' => true]],
            ],
        ];

        self::assertContains('related_from', $this->service->getReadFields('tx_test'));
        self::assertNotContains('related_from', $this->service->getWritableFields('tx_test'));
    }

    /** MM fields are exactly the select/group columns with an MM table; inline and file fields keep their own handling. */
    public function testGetMMFieldsReturnsOnlyMMSelectAndGroupColumns(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => ['tstamp' => 'tstamp'],
            'columns' => [
                'groups' => ['config' => ['type' => 'select', 'foreign_table' => 'tx_test_group', 'MM' => 'tx_test_group_mm']],
                'related' => ['config' => ['type' => 'group', 'allowed' => 'tt_content', 'MM' => 'tx_test_related_mm']],
                'type' => ['config' => ['type' => 'select', 'items' => []]],
                'pages' => ['config' => ['type' => 'group', 'allowed' => 'pages']],
                'children' => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_test_child', 'foreign_field' => 'parent']],
                'image' => ['config' => ['type' => 'file']],
                'media' => ['config' => ['type' => 'inline', 'foreign_table' => 'sys_file_reference']],
                'categories' => ['config' => ['type' => 'category']],
                'tstamp' => ['config' => ['type' => 'select', 'MM' => 'tx_test_bogus_mm']],
            ],
        ];

        $mmFields = $this->service->getMMFields('tx_test');

        self::assertSame(['groups', 'related'], array_keys($mmFields));
        self::assertSame('tx_test_group_mm', $mmFields['groups']['MM']);
        self::assertTrue($this->service->isMMField('tx_test', 'groups'));
        self::assertFalse($this->service->isMMField('tx_test', 'type'));
        self::assertFalse($this->service->isMMField('tx_test', 'children'));
        self::assertFalse($this->service->isMMField('tx_test', 'nonexistent'));

        // The unrelated field kinds are left exactly as before: not readable, but file fields still discoverable.
        $readFields = $this->service->getReadFields('tx_test');
        self::assertNotContains('children', $readFields);
        self::assertNotContains('image', $readFields);
        self::assertNotContains('media', $readFields);
        self::assertNotContains('categories', $readFields);
        self::assertSame(['image', 'media'], $this->service->getFileFields('tx_test'));
    }

    public function testGetMMFieldsReturnsEmptyForMissingTable(): void
    {
        self::assertSame([], $this->service->getMMFields('nonexistent_table'));
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
                'sys_language_uid' => ['config' => ['type' => 'language']],
                'l10n_parent' => ['config' => ['type' => 'number']],
                'l10n_source' => ['config' => ['type' => 'number']],
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
        // sortby field is included as a readable field even though it cannot be written directly
        self::assertContains('sorting', $fields);
        // languageField and transOrigPointerField are user-editable
        self::assertContains('sys_language_uid', $fields);
        self::assertContains('l10n_parent', $fields);
        // translationSource is a system field
        self::assertNotContains('l10n_source', $fields);
        // enablecolumns are user-editable, not system fields
        self::assertContains('hidden', $fields);
        self::assertContains('starttime', $fields);
        self::assertContains('endtime', $fields);
        self::assertContains('fe_group', $fields);
        // l10n_diffsource is passthrough type, excluded by type check
        self::assertNotContains('l10n_diffsource', $fields);
    }

    public function testGetReadFieldsIncludesSortByEvenWithoutColumnDefinition(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => ['sortby' => 'sorting'],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
            ],
        ];

        self::assertContains('sorting', $this->service->getReadFields('tx_test'));
    }

    public function testGetWritableFieldsExcludesSortBy(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => ['sortby' => 'sorting'],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'sorting' => ['config' => ['type' => 'number']],
            ],
        ];

        self::assertNotContains('sorting', $this->service->getWritableFields('tx_test'));
    }

    public function testGetWritableFieldsIncludesEnableColumns(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [
                'enablecolumns' => [
                    'disabled' => 'hidden',
                    'starttime' => 'starttime',
                    'endtime' => 'endtime',
                ],
            ],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'hidden' => ['config' => ['type' => 'check']],
                'starttime' => ['config' => ['type' => 'datetime']],
                'endtime' => ['config' => ['type' => 'datetime']],
            ],
        ];

        $fields = $this->service->getWritableFields('tx_test');

        self::assertContains('title', $fields);
        self::assertContains('hidden', $fields);
        self::assertContains('starttime', $fields);
        self::assertContains('endtime', $fields);
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

    public function testGetFieldsSchemaMarksMMSelectAsRelation(): void
    {
        $GLOBALS['TCA']['tx_test_team'] = [
            'ctrl' => [],
            'columns' => [
                'groups' => [
                    'label' => 'Groups',
                    'config' => [
                        'type' => 'select',
                        'renderType' => 'selectCheckBox',
                        'foreign_table' => 'tx_test_group',
                        'MM' => 'tx_test_team_group_mm',
                        'minitems' => 1,
                        'maxitems' => 5,
                    ],
                ],
            ],
        ];

        $result = $this->service->getFieldsSchema('tx_test_team');

        self::assertCount(1, $result['fields']);
        $field = $result['fields'][0];
        self::assertSame('groups', $field['name']);
        self::assertSame('select', $field['type']);
        self::assertSame('selectCheckBox', $field['renderType']);
        self::assertSame('tx_test_group', $field['foreignTable']);
        self::assertSame('mm', $field['relation']);
        self::assertSame('tx_test_team_group_mm', $field['mm']);
        self::assertSame(1, $field['minitems']);
        self::assertSame(5, $field['maxitems']);
    }

    public function testGetFieldsSchemaMarksMMGroupWithAllowedTables(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'related' => [
                    'config' => [
                        'type' => 'group',
                        'allowed' => 'tt_content, pages',
                        'MM' => 'tx_test_related_mm',
                        'maxitems' => 10,
                    ],
                ],
            ],
        ];

        $field = $this->service->getFieldsSchema('tx_test')['fields'][0];

        self::assertSame('group', $field['type']);
        self::assertSame(['tt_content', 'pages'], $field['allowed']);
        self::assertSame('mm', $field['relation']);
        self::assertSame('tx_test_related_mm', $field['mm']);
        self::assertSame(10, $field['maxitems']);
        self::assertArrayNotHasKey('minitems', $field);
        self::assertArrayNotHasKey('foreignTable', $field);
    }

    /** A select or group without an MM table keeps its raw-column format and must not be marked as a relation. */
    public function testGetFieldsSchemaDoesNotMarkPlainSelectOrGroupAsRelation(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'category' => ['config' => ['type' => 'select', 'renderType' => 'selectSingle', 'foreign_table' => 'sys_category']],
                'pages' => ['config' => ['type' => 'group', 'allowed' => 'pages', 'maxitems' => 3]],
            ],
        ];

        $fields = $this->service->getFieldsSchema('tx_test')['fields'];

        self::assertArrayNotHasKey('relation', $fields[0]);
        self::assertArrayNotHasKey('mm', $fields[0]);
        self::assertSame('sys_category', $fields[0]['foreignTable']);
        self::assertArrayNotHasKey('relation', $fields[1]);
        self::assertSame(['pages'], $fields[1]['allowed']);
        self::assertSame(3, $fields[1]['maxitems']);
    }

    /** Inline and file-reference columns are out of scope: not in the schema, not readable, still reported as file fields. */
    public function testGetFieldsSchemaLeavesInlineAndFileReferenceFieldsOut(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'ctrl' => [],
            'columns' => [
                'title' => ['config' => ['type' => 'input']],
                'children' => ['config' => ['type' => 'inline', 'foreign_table' => 'tx_test_child', 'foreign_field' => 'parent']],
                'media' => ['config' => ['type' => 'inline', 'foreign_table' => 'sys_file_reference']],
                'image' => ['config' => ['type' => 'file']],
            ],
        ];

        $names = array_column($this->service->getFieldsSchema('tx_test')['fields'], 'name');

        self::assertSame(['title'], $names);
        self::assertSame(['media', 'image'], $this->service->getFileFields('tx_test'));
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

    public function testGetLabelFieldReturnsCtrlLabel(): void
    {
        $GLOBALS['TCA']['tx_test'] = ['ctrl' => ['label' => 'title'], 'columns' => []];

        self::assertSame('title', $this->service->getLabelField('tx_test'));
    }

    public function testGetLabelFieldReturnsNullWithoutLabelOrTable(): void
    {
        $GLOBALS['TCA']['tx_test'] = ['ctrl' => ['label' => ''], 'columns' => []];

        self::assertNull($this->service->getLabelField('tx_test'));
        self::assertNull($this->service->getLabelField('nonexistent_table'));
    }
}
