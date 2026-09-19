<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Helper;

use MarekSkopal\MsMcpServer\Tool\Helper\ExplicitFields;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ExplicitFields::class)]
final class ExplicitFieldsTest extends TestCase
{
    private const array WRITABLE = ['title', 'root', 'description'];

    public function testExplicitValuesAloneAreWrittenAsIs(): void
    {
        [$data, $ignored] = ExplicitFields::merge(['title' => 'Main', 'root' => 1], '', self::WRITABLE);

        self::assertSame(['title' => 'Main', 'root' => 1], $data);
        self::assertSame([], $ignored);
    }

    public function testFieldsJsonAddsWritableExtras(): void
    {
        $fields = json_encode(['description' => 'From JSON'], JSON_THROW_ON_ERROR);

        [$data] = ExplicitFields::merge(['title' => 'Main'], $fields, self::WRITABLE);

        self::assertSame(['description' => 'From JSON', 'title' => 'Main'], $data);
    }

    public function testExplicitValuesWinOverTheSameKeyInFieldsJson(): void
    {
        $fields = json_encode(['title' => 'Overridden'], JSON_THROW_ON_ERROR);

        [$data] = ExplicitFields::merge(['title' => 'Explicit'], $fields, self::WRITABLE);

        self::assertSame('Explicit', $data['title']);
    }

    public function testNonWritableFieldsAreDroppedAndNamed(): void
    {
        $fields = json_encode(['sitetitle' => 'gone', 'description' => 'kept'], JSON_THROW_ON_ERROR);

        [$data, $ignored] = ExplicitFields::merge(['title' => 'Main'], $fields, self::WRITABLE);

        self::assertArrayNotHasKey('sitetitle', $data);
        self::assertSame(['sitetitle'], $ignored);
    }

    public function testMalformedFieldsJsonIsRefused(): void
    {
        $this->expectException(ToolCallException::class);

        ExplicitFields::merge(['title' => 'Main'], '{not json', self::WRITABLE);
    }
}
