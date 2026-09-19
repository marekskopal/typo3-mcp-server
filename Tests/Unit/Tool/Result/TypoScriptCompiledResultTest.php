<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Result;

use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\TypoScriptCompiledResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypoScriptCompiledResult::class)]
final class TypoScriptCompiledResultTest extends TestCase
{
    public function testASectionThatWasNotRequestedIsOmittedRatherThanNull(): void
    {
        $result = JsonResult::of(new TypoScriptCompiledResult(1, '', null, ['page' => 'PAGE']));

        self::assertSame(['pageId' => 1, 'setup' => ['page' => 'PAGE']], $result);
    }

    public function testAnEmptySectionIsStillReportedWhenItWasRequested(): void
    {
        $result = JsonResult::of(new TypoScriptCompiledResult(1, '', [], null));

        self::assertSame(['pageId' => 1, 'constants' => []], $result);
    }

    public function testThePathIsEchoedBackOnlyWhenOneWasGiven(): void
    {
        $result = JsonResult::of(new TypoScriptCompiledResult(1, 'lib.contentElement', null, []));

        self::assertSame('lib.contentElement', $result['path']);
    }

    public function testTruncationCarriesAnInstructionRatherThanJustAFlag(): void
    {
        $result = JsonResult::of(new TypoScriptCompiledResult(1, '', null, ['page' => 'PAGE'], true));

        self::assertTrue($result['truncated']);
        self::assertStringContainsString('path', $result['message']);
    }
}
