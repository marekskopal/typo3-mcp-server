<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\FlexFormFieldConverter;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\FlexForm\Exception\InvalidIdentifierException;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;

#[CoversClass(FlexFormFieldConverter::class)]
final class FlexFormFieldConverterTest extends TestCase
{
    private const string XML = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>'
        . '<T3FlexForms><data>'
        . '<sheet index="sDEF"><language index="lDEF">'
        . '<field index="settings.templateLayout"><value index="vDEF">split</value></field>'
        . '<field index="settings.limit"><value index="vDEF">5</value></field>'
        . '</language></sheet>'
        . '<sheet index="list"><language index="lDEF">'
        . '<field index="settings.orderBy"><value index="vDEF">datetime</value></field>'
        . '</language></sheet>'
        . '</data></T3FlexForms>';

    /** The data structure FlexFormTools hands back for the record, after ensureDefaultSheet(). */
    private const array DATA_STRUCTURE = [
        'sheets' => [
            'sDEF' => [
                'ROOT' => [
                    'el' => [
                        'settings.templateLayout' => ['label' => 'Layout', 'config' => ['type' => 'select']],
                        'settings.limit' => ['label' => 'Limit', 'config' => ['type' => 'number']],
                        'settings.shared' => ['label' => 'Shared', 'config' => ['type' => 'input']],
                    ]]],
            'list' => [
                'ROOT' => [
                    'el' => [
                        'settings.orderBy' => ['label' => 'Order', 'config' => ['type' => 'input']],
                        'settings.shared' => ['label' => 'Shared', 'config' => ['type' => 'input']],
                        'settings.slides' => ['section' => 1, 'el' => []],
                    ]]],
        ],
    ];

    protected function setUp(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'ctrl' => ['type' => 'CType'],
            'columns' => [
                'header' => ['config' => ['type' => 'input']],
                'pi_flexform' => ['config' => ['type' => 'flex']],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
    }

    public function testToWireTurnsStoredXmlIntoSheetsOfValues(): void
    {
        $row = $this->createConverter()->toWire('tt_content', ['uid' => 7, 'header' => 'News', 'pi_flexform' => self::XML]);

        self::assertSame(
            [
                'uid' => 7,
                'header' => 'News',
                'pi_flexform' => [
                    'sDEF' => ['settings.templateLayout' => 'split', 'settings.limit' => '5'],
                    'list' => ['settings.orderBy' => 'datetime'],
                ],
            ],
            $row,
        );
    }

    public function testToWireLeavesEmptyAndUnparsableValuesAlone(): void
    {
        $converter = $this->createConverter();

        self::assertSame(['pi_flexform' => ''], $converter->toWire('tt_content', ['pi_flexform' => '']));
        self::assertSame(['pi_flexform' => 'not xml'], $converter->toWire('tt_content', ['pi_flexform' => 'not xml']));
        self::assertSame(['uid' => 1], $converter->toWire('tt_content', ['uid' => 1]));
    }

    public function testToDataHandlerResolvesAFlatObjectAgainstTheDataStructure(): void
    {
        $fields = $this->createConverter()->toDataHandler(
            'tt_content',
            ['header' => 'News', 'pi_flexform' => ['settings.templateLayout' => 'split', 'settings.orderBy' => 'title', 'settings.limit' => 5]],
            ['uid' => 7, 'CType' => 'news_pi1'],
        );

        self::assertSame(
            [
                'header' => 'News',
                'pi_flexform' => [
                    'data' => [
                        'sDEF' => ['lDEF' => ['settings.templateLayout' => ['vDEF' => 'split'], 'settings.limit' => ['vDEF' => '5']]],
                        'list' => ['lDEF' => ['settings.orderBy' => ['vDEF' => 'title']]],
                    ]],
            ],
            $fields,
        );
    }

    public function testToDataHandlerAcceptsTheSheetKeyedShapeToWireReturns(): void
    {
        $fields = $this->createConverter()->toDataHandler(
            'tt_content',
            ['pi_flexform' => ['list' => ['settings.shared' => true], 'sDEF' => ['settings.shared' => null]]],
            [],
        );

        self::assertSame(
            [
                'pi_flexform' => [
                    'data' => [
                        'list' => ['lDEF' => ['settings.shared' => ['vDEF' => '1']]],
                        'sDEF' => ['lDEF' => ['settings.shared' => ['vDEF' => '']]],
                    ]]],
            $fields,
        );
    }

    public function testToDataHandlerPassesASectionThroughInItsNativeShape(): void
    {
        $section = ['el' => ['a1' => ['slide' => ['el' => ['title' => ['vDEF' => 'One']]]]]];

        $fields = $this->createConverter()->toDataHandler('tt_content', ['pi_flexform' => ['settings.slides' => $section]], []);

        self::assertSame(['pi_flexform' => ['data' => ['list' => ['lDEF' => ['settings.slides' => $section]]]]], $fields);
    }

    public function testToDataHandlerLeavesXmlStringsUntouched(): void
    {
        $flexFormTools = $this->createMock(FlexFormTools::class);
        $flexFormTools->expects(self::never())->method('getDataStructureIdentifier');

        $converter = new FlexFormFieldConverter($flexFormTools, new TcaSchemaService());

        self::assertSame(['pi_flexform' => self::XML], $converter->toDataHandler('tt_content', ['pi_flexform' => self::XML], []));
        self::assertFalse($converter->hasObjectValues('tt_content', ['pi_flexform' => self::XML, 'header' => ['x']]));
    }

    public function testUnknownFieldIsRejectedWithTheAvailableOnes(): void
    {
        // The typo the issue describes: stored without complaint before.
        try {
            $this->createConverter()->toDataHandler('tt_content', ['pi_flexform' => ['settings.templateLayot' => 'split']], []);
            self::fail('Expected a ToolCallException');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('"settings.templateLayot" is not a field', $e->getMessage());
            self::assertStringContainsString('sDEF.settings.templateLayout', $e->getMessage());
        }
    }

    public function testFieldInSeveralSheetsMustNameTheSheet(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"settings.shared" exists in several sheets (sDEF, list)');

        $this->createConverter()->toDataHandler('tt_content', ['pi_flexform' => ['settings.shared' => 'x']], []);
    }

    public function testUnknownFieldInANamedSheetIsRejected(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"list.settings.limit" is not a field');

        $this->createConverter()->toDataHandler('tt_content', ['pi_flexform' => ['list' => ['settings.limit' => 1]]], []);
    }

    public function testNonScalarValueForAPlainFieldIsRejected(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"settings.limit" takes a single value');

        $this->createConverter()->toDataHandler('tt_content', ['pi_flexform' => ['settings.limit' => [5]]], []);
    }

    public function testUnresolvableDataStructureAsksForXml(): void
    {
        $flexFormTools = $this->createStub(FlexFormTools::class);
        $flexFormTools->method('getDataStructureIdentifier')->willThrowException(new InvalidIdentifierException('no ds', 1));

        $converter = new FlexFormFieldConverter($flexFormTools, new TcaSchemaService());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Could not resolve the FlexForm data structure of tt_content.pi_flexform');

        $converter->toDataHandler('tt_content', ['pi_flexform' => ['settings.limit' => 5]], []);
    }

    public function testTheDataStructureIsResolvedFromTheStoredRowWithTheNewValuesOnTop(): void
    {
        $flexFormTools = $this->createMock(FlexFormTools::class);
        $flexFormTools->expects(self::once())
            ->method('getDataStructureIdentifier')
            ->with(
                ['config' => ['type' => 'flex']],
                'tt_content',
                'pi_flexform',
                self::callback(static fn (array $row): bool => $row['CType'] === 'news_pi1' && $row['uid'] === 7),
            )
            ->willReturn('{"type":"tca"}');
        $flexFormTools->method('parseDataStructureByIdentifier')->willReturn(self::DATA_STRUCTURE);

        $converter = new FlexFormFieldConverter($flexFormTools, new TcaSchemaService());
        $converter->toDataHandler(
            'tt_content',
            ['CType' => 'news_pi1', 'pi_flexform' => ['settings.limit' => 5]],
            ['uid' => 7, 'CType' => 'text'],
        );
    }

    private function createConverter(): FlexFormFieldConverter
    {
        $flexFormTools = $this->createStub(FlexFormTools::class);
        $flexFormTools->method('getDataStructureIdentifier')->willReturn('{"type":"tca"}');
        $flexFormTools->method('parseDataStructureByIdentifier')->willReturn(self::DATA_STRUCTURE);

        return new FlexFormFieldConverter($flexFormTools, new TcaSchemaService());
    }
}
