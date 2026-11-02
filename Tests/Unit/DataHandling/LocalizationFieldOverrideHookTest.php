<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\DataHandling;

use MarekSkopal\MsMcpServer\DataHandling\LocalizationFieldOverrideHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;

#[CoversClass(LocalizationFieldOverrideHook::class)]
final class LocalizationFieldOverrideHookTest extends TestCase
{
    public function testReplacesTheValuesOfTheNewTranslationBeforeDataHandlerStoresIt(): void
    {
        // The NEW page core's localize hands to its nested DataHandler: prefixed title, hidden by
        // hideAtCopy, no slug yet — DataHandler would build "/translate-to-czech-features" from it.
        $incoming = ['title' => '[Translate to Czech:] Features', 'hidden' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 12, 'pid' => 3];

        $hook = $this->createHook(['title' => 'Funkce', 'hidden' => 0]);
        $hook->processDatamap_preProcessFieldArray($incoming, 'pages', 'NEW1234', $this->createStub(DataHandler::class));

        self::assertSame(
            ['title' => 'Funkce', 'hidden' => 0, 'sys_language_uid' => 1, 'l10n_parent' => 12, 'pid' => 3],
            $incoming,
        );
        self::assertFalse($hook->release('pages', 12, 1), 'consumed overrides must not be reported as pending');
    }

    public function testAppliesOnlyOnce(): void
    {
        $hook = $this->createHook(['title' => 'Funkce']);
        $dataHandler = $this->createStub(DataHandler::class);

        $first = ['title' => 'a', 'sys_language_uid' => 1, 'l10n_parent' => 12];
        $hook->processDatamap_preProcessFieldArray($first, 'pages', 'NEW1', $dataHandler);
        $second = ['title' => 'b', 'sys_language_uid' => 1, 'l10n_parent' => 12];
        $hook->processDatamap_preProcessFieldArray($second, 'pages', 'NEW2', $dataHandler);

        self::assertSame('Funkce', $first['title']);
        self::assertSame('b', $second['title']);
    }

    public function testLeavesOtherRecordsUntouched(): void
    {
        $hook = $this->createHook(['title' => 'Funkce']);
        $dataHandler = $this->createStub(DataHandler::class);

        // An inline child localized in the same run: its pointer names its own source record.
        $child = ['title' => 'x', 'sys_language_uid' => 1, 'l10n_parent' => 99];
        $hook->processDatamap_preProcessFieldArray($child, 'pages', 'NEW1', $dataHandler);
        // Another language.
        $otherLanguage = ['title' => 'x', 'sys_language_uid' => 2, 'l10n_parent' => 12];
        $hook->processDatamap_preProcessFieldArray($otherLanguage, 'pages', 'NEW2', $dataHandler);
        // Another table.
        $otherTable = ['title' => 'x', 'sys_language_uid' => 1, 'l10n_parent' => 12];
        $hook->processDatamap_preProcessFieldArray($otherTable, 'tt_content', 'NEW3', $dataHandler);
        // An update of an existing record, not the NEW translation.
        $existing = ['title' => 'x', 'sys_language_uid' => 1, 'l10n_parent' => 12];
        $hook->processDatamap_preProcessFieldArray($existing, 'pages', 12, $dataHandler);

        self::assertSame('x', $child['title']);
        self::assertSame('x', $otherLanguage['title']);
        self::assertSame('x', $otherTable['title']);
        self::assertSame('x', $existing['title']);
        self::assertTrue($hook->release('pages', 12, 1));
    }

    public function testMatchesStringValuedPointers(): void
    {
        $hook = $this->createHook(['title' => 'Funkce']);
        $incoming = ['title' => 'x', 'sys_language_uid' => '1', 'l10n_parent' => '12'];

        $hook->processDatamap_preProcessFieldArray($incoming, 'pages', 'NEW1', $this->createStub(DataHandler::class));

        self::assertSame('Funkce', $incoming['title']);
    }

    public function testReleaseClearsPendingOverrides(): void
    {
        $hook = $this->createHook(['title' => 'Funkce']);

        self::assertTrue($hook->release('pages', 12, 1));

        $incoming = ['title' => 'x', 'sys_language_uid' => 1, 'l10n_parent' => 12];
        $hook->processDatamap_preProcessFieldArray($incoming, 'pages', 'NEW1', $this->createStub(DataHandler::class));
        self::assertSame('x', $incoming['title']);
    }

    /** @param array<string, mixed> $fields */
    private function createHook(array $fields): LocalizationFieldOverrideHook
    {
        $hook = new LocalizationFieldOverrideHook();
        $hook->register('pages', 12, 1, 'sys_language_uid', 'l10n_parent', $fields);

        return $hook;
    }
}
