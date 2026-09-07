<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\MmFieldNormalizer;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MmFieldNormalizer::class)]
final class MmFieldNormalizerTest extends TestCase
{
    private MmFieldNormalizer $normalizer;

    protected function setUp(): void
    {
        $GLOBALS['TCA'] = [
            'tx_test_team' => [
                'ctrl' => [],
                'columns' => [
                    'title' => ['config' => ['type' => 'input']],
                    'groups' => ['config' => ['type' => 'select', 'foreign_table' => 'tx_test_group', 'MM' => 'tx_test_team_group_mm']],
                    'members' => ['config' => ['type' => 'group', 'allowed' => 'tx_test_player', 'MM' => 'tx_test_team_player_mm']],
                    'related' => ['config' => ['type' => 'group', 'allowed' => 'tt_content,pages', 'MM' => 'tx_test_team_related_mm']],
                    'anything' => ['config' => ['type' => 'group', 'allowed' => '*', 'MM' => 'tx_test_team_anything_mm']],
                    'pages' => ['config' => ['type' => 'group', 'allowed' => 'pages']],
                ],
            ],
        ];

        $this->normalizer = new MmFieldNormalizer(new TcaSchemaService());
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
    }

    public function testLeavesTablesWithoutMMFieldsAlone(): void
    {
        $fields = ['header' => 'Text', 'pages' => [1, 2]];

        self::assertSame($fields, $this->normalizer->normalize('tt_content', $fields));
    }

    /** Non-MM fields keep their value and type, including a plain group field's list. */
    public function testLeavesNonMMFieldsAlone(): void
    {
        $fields = ['title' => 'Team', 'pages' => [1, 2], 'hidden' => 0];

        self::assertSame($fields, $this->normalizer->normalize('tx_test_team', $fields));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function acceptedValues(): iterable
    {
        yield 'array of ints' => [[20, 21], '20,21'];
        yield 'array of numeric strings' => [['20', '21'], '20,21'];
        yield 'single int' => [20, '20'];
        yield 'comma-separated string' => ['20,21', '20,21'];
        yield 'comma-separated string with spaces' => [' 20, 21 ,', '20,21'];
        yield 'empty array clears' => [[], ''];
        yield 'empty string clears' => ['', ''];
        yield 'null clears' => [null, ''];
    }

    #[DataProvider('acceptedValues')]
    public function testNormalizesSelectMMFieldToCommaSeparatedUids(mixed $value, string $expected): void
    {
        $result = $this->normalizer->normalize('tx_test_team', ['title' => 'Team', 'groups' => $value]);

        self::assertSame(['title' => 'Team', 'groups' => $expected], $result);
    }

    public function testNormalizesEveryMMFieldPresent(): void
    {
        $result = $this->normalizer->normalize('tx_test_team', ['groups' => [20], 'members' => '7,8']);

        self::assertSame(['groups' => '20', 'members' => '7,8'], $result);
    }

    public function testDoesNotAddAbsentMMFields(): void
    {
        self::assertSame(['title' => 'Team'], $this->normalizer->normalize('tx_test_team', ['title' => 'Team']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedValues(): iterable
    {
        yield 'non-numeric string entry' => [['20', 'abc']];
        yield 'non-numeric in comma list' => ['20,abc'];
        yield 'zero' => [[0]];
        yield 'negative' => [[-3]];
        yield 'float' => [[2.5]];
        yield 'bool' => [true];
        yield 'nested array' => [[[20]]];
        yield 'JSON object (associative array after decoding)' => [['uid' => 20, 'title' => 'Foo']];
        yield 'stdClass' => [(object) ['uid' => 20]];
        yield 'table_uid on a select field' => [['tx_test_group_20']];
    }

    #[DataProvider('rejectedValues')]
    public function testRejectsInvalidEntriesNamingTheField(mixed $value): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionCode(1725900001);
        $this->expectExceptionMessage('Field "groups" is a many-to-many relation and expects a list of UIDs');

        $this->normalizer->normalize('tx_test_team', ['groups' => $value]);
    }

    public function testErrorNamesAJsonObjectAsTheWrongShape(): void
    {
        try {
            $this->normalizer->normalize('tx_test_team', ['groups' => ['a' => 20, 'b' => 21]]);
            self::fail('Expected a ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('got a JSON object instead of an array', $e->getMessage());
        }
    }

    public function testErrorNamesTheOffendingEntry(): void
    {
        try {
            $this->normalizer->normalize('tx_test_team', ['groups' => [20, 'abc']]);
            self::fail('Expected a ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('"abc" is not a UID', $e->getMessage());
        }
    }

    /** A group field with one allowed table takes bare UIDs and, equivalently, its own table_uid form. */
    public function testSingleTableGroupAcceptsBareUidsAndTablePrefixedForm(): void
    {
        $result = $this->normalizer->normalize('tx_test_team', ['members' => [7, 'tx_test_player_8', '9']]);

        self::assertSame(['members' => '7,tx_test_player_8,9'], $result);
    }

    public function testSingleTableGroupRejectsOtherTables(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('table "pages" is not allowed here (allowed: tx_test_player)');

        $this->normalizer->normalize('tx_test_team', ['members' => ['pages_8']]);
    }

    /** With several allowed tables a bare UID does not say which table it belongs to. */
    public function testMultiTableGroupRequiresTablePrefixedForm(): void
    {
        $result = $this->normalizer->normalize('tx_test_team', ['related' => ['tt_content_12', 'pages_3']]);
        self::assertSame(['related' => 'tt_content_12,pages_3'], $result);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"12" is ambiguous because the field allows several tables; use the table_uid form (e.g. "tt_content_12")');

        $this->normalizer->normalize('tx_test_team', ['related' => [12]]);
    }

    public function testMultiTableGroupRejectsTablesOutsideAllowed(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('table "be_users" is not allowed here (allowed: tt_content, pages)');

        $this->normalizer->normalize('tx_test_team', ['related' => ['be_users_1']]);
    }

    public function testWildcardGroupAcceptsAnyTablePrefixedForm(): void
    {
        $result = $this->normalizer->normalize('tx_test_team', ['anything' => 'sys_category_4, tt_content_12']);

        self::assertSame(['anything' => 'sys_category_4,tt_content_12'], $result);
    }

    public function testWildcardGroupStillRejectsBareUids(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('is ambiguous because the field allows several tables');

        $this->normalizer->normalize('tx_test_team', ['anything' => [4]]);
    }
}
