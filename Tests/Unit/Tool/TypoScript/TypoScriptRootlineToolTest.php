<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\TypoScript;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TypoScriptCompilerService;
use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\TypoScript\TypoScriptRootlineTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypoScriptRootlineTool::class)]
final class TypoScriptRootlineToolTest extends TestCase
{
    private const array CHAIN = [
        'pageId' => 12,
        'siteIdentifier' => 'main',
        'siteRootPageId' => 1,
        'sets' => ['typo3/fluid-styled-content'],
        'rootline' => [['uid' => 12, 'title' => 'Sub'], ['uid' => 1, 'title' => 'Home']],
        'templates' => [
            [
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
            ],
        ],
    ];

    public function testReportsTheWholeChain(): void
    {
        $result = JsonResult::of($this->createTool()->execute(12));

        self::assertSame(12, $result['pageId']);
        self::assertSame('main', $result['siteIdentifier']);
        self::assertSame(1, $result['siteRootPageId']);
        self::assertSame(['typo3/fluid-styled-content'], $result['sets']);
        self::assertSame([['uid' => 12, 'title' => 'Sub'], ['uid' => 1, 'title' => 'Home']], $result['rootline']);
        self::assertSame(3, $result['templates'][0]['uid']);
        self::assertTrue($result['templates'][0]['hasSetup']);
    }

    public function testPageOutsideASiteIsStillAnswered(): void
    {
        $compiler = $this->createStub(TypoScriptCompilerService::class);
        $compiler->method('resolveTemplateChain')->willReturn([
            'pageId' => 99,
            'siteIdentifier' => null,
            'siteRootPageId' => null,
            'sets' => [],
            'rootline' => [],
            'templates' => [],
        ]);

        $result = JsonResult::of($this->createTool($compiler)->execute(99));

        self::assertNull($result['siteIdentifier']);
        self::assertSame([], $result['templates']);
    }

    public function testRefusesWithoutReadAccess(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(false);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Access denied');

        $this->createTool(null, $permissionService)->execute(12);
    }

    private function createTool(
        ?TypoScriptCompilerService $compiler = null,
        ?PermissionService $permissionService = null,
    ): TypoScriptRootlineTool {
        if ($compiler === null) {
            $compiler = $this->createStub(TypoScriptCompilerService::class);
            $compiler->method('resolveTemplateChain')->willReturn(self::CHAIN);
        }

        if ($permissionService === null) {
            $permissionService = $this->createStub(PermissionService::class);
            $permissionService->method('canSelectTable')->willReturn(true);
        }

        return new TypoScriptRootlineTool($compiler, $permissionService);
    }
}
