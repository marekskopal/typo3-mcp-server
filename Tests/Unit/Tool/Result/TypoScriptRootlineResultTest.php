<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\Result;

use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\Result\TypoScriptRootlineResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypoScriptRootlineResult::class)]
final class TypoScriptRootlineResultTest extends TestCase
{
    public function testSerializesEveryPartOfTheChain(): void
    {
        $template = [
            'uid' => 3,
            'pid' => 1,
            'title' => 'Main',
            'root' => 1,
            'clear' => 3,
            'hidden' => 0,
            'basedOn' => '',
            'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
            'includeStaticAfterBasedOn' => 0,
            'hasConstants' => true,
            'hasSetup' => true,
        ];

        $result = JsonResult::of(new TypoScriptRootlineResult(
            12,
            'main',
            1,
            ['typo3/fluid-styled-content'],
            [['uid' => 12, 'title' => 'Sub']],
            [$template],
        ));

        self::assertSame([
            'pageId' => 12,
            'siteIdentifier' => 'main',
            'siteRootPageId' => 1,
            'sets' => ['typo3/fluid-styled-content'],
            'rootline' => [['uid' => 12, 'title' => 'Sub']],
            'templates' => [$template],
        ], $result);
    }

    public function testAPageOutsideASiteKeepsTheSiteKeysAsNull(): void
    {
        $result = JsonResult::of(new TypoScriptRootlineResult(99, null, null, [], [], []));

        self::assertNull($result['siteIdentifier']);
        self::assertNull($result['siteRootPageId']);
        self::assertSame([], $result['sets']);
    }
}
