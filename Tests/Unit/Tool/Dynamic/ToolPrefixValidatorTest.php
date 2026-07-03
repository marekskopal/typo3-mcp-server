<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Dynamic;

use MarekSkopal\MsMcpServer\Tool\Dynamic\ToolPrefixValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolPrefixValidator::class)]
final class ToolPrefixValidatorTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function validPrefixes(): array
    {
        return [
            'simple' => ['news'],
            'with underscore and digits' => ['news_2024'],
            'max length' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('validPrefixes')]
    public function testAcceptsValidPrefix(string $prefix): void
    {
        self::assertTrue(ToolPrefixValidator::isValid($prefix));
        self::assertNull(ToolPrefixValidator::validate($prefix));
    }

    /** @return array<string, array{string}> */
    public static function malformedPrefixes(): array
    {
        return [
            'uppercase' => ['News'],
            'leading digit' => ['2news'],
            'leading underscore' => ['_news'],
            'space' => ['my news'],
            'hyphen' => ['my-news'],
            'empty' => [''],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('malformedPrefixes')]
    public function testRejectsMalformedPrefix(string $prefix): void
    {
        self::assertFalse(ToolPrefixValidator::isValid($prefix));
        self::assertStringContainsString('Invalid prefix', (string) ToolPrefixValidator::validate($prefix));
    }

    public function testRejectsEveryReservedPrefix(): void
    {
        foreach (ToolPrefixValidator::RESERVED_PREFIXES as $reserved) {
            self::assertFalse(ToolPrefixValidator::isValid($reserved), $reserved);
            self::assertStringContainsString('reserved', (string) ToolPrefixValidator::validate($reserved), $reserved);
        }
    }

    public function testWorkspaceIsReserved(): void
    {
        // Workspace tools exist as built-ins; the prefix must not be shadowable by a discovered table.
        self::assertFalse(ToolPrefixValidator::isValid('workspace'));
    }
}
