<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Tool\TypoScript;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TypoScriptCompilerService;
use MarekSkopal\MsMcpServer\Tests\Unit\Support\JsonResult;
use MarekSkopal\MsMcpServer\Tool\TypoScript\TypoScriptActiveTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypoScriptActiveTool::class)]
final class TypoScriptActiveToolTest extends TestCase
{
    private const array COMPILED = [
        'constants' => ['styles.content.textmedia.maxW' => '600'],
        'setup' => [
            'lib.contentElement' => 'FLUIDTEMPLATE',
            'lib.contentElement.templateName' => 'Default',
            'page' => 'PAGE',
        ],
    ];

    public function testReturnsSetupByDefault(): void
    {
        $result = JsonResult::of($this->createTool()->execute(1));

        self::assertSame(1, $result['pageId']);
        self::assertSame(self::COMPILED['setup'], $result['setup']);
        self::assertArrayNotHasKey('constants', $result);
        self::assertArrayNotHasKey('path', $result);
        self::assertArrayNotHasKey('truncated', $result);
    }

    public function testReturnsConstantsOnly(): void
    {
        $result = JsonResult::of($this->createTool()->execute(1, 'constants'));

        self::assertSame(self::COMPILED['constants'], $result['constants']);
        self::assertArrayNotHasKey('setup', $result);
    }

    public function testReturnsBothSections(): void
    {
        $result = JsonResult::of($this->createTool()->execute(1, 'both'));

        self::assertSame(self::COMPILED['constants'], $result['constants']);
        self::assertSame(self::COMPILED['setup'], $result['setup']);
    }

    public function testPathFilterKeepsThePathAndItsChildren(): void
    {
        $result = JsonResult::of($this->createTool()->execute(1, 'setup', 'lib.contentElement'));

        self::assertSame('lib.contentElement', $result['path']);
        self::assertSame(
            ['lib.contentElement' => 'FLUIDTEMPLATE', 'lib.contentElement.templateName' => 'Default'],
            $result['setup'],
        );
    }

    public function testPathFilterDoesNotMatchASiblingSharingThePrefix(): void
    {
        $compiler = $this->createStub(TypoScriptCompilerService::class);
        $compiler->method('compile')->willReturn([
            'constants' => [],
            'setup' => ['lib.content' => 'A', 'lib.contentElement' => 'B'],
        ]);

        $result = JsonResult::of($this->createTool($compiler)->execute(1, 'setup', 'lib.content'));

        self::assertSame(['lib.content' => 'A'], $result['setup']);
    }

    public function testPathIsTrimmed(): void
    {
        $result = JsonResult::of($this->createTool()->execute(1, 'setup', '  lib.contentElement  '));

        self::assertSame('lib.contentElement', $result['path']);
    }

    public function testOutputIsCappedAndFlagged(): void
    {
        $setup = [];
        for ($i = 0; $i < 2100; $i++) {
            $setup['lib.item' . $i] = (string) $i;
        }

        $compiler = $this->createStub(TypoScriptCompilerService::class);
        $compiler->method('compile')->willReturn(['constants' => [], 'setup' => $setup]);

        $result = JsonResult::of($this->createTool($compiler)->execute(1));

        self::assertCount(2000, $result['setup']);
        self::assertTrue($result['truncated']);
        self::assertStringContainsString('path', $result['message']);
    }

    public function testUnknownTypeIsRefused(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown type "page"');

        $this->createTool()->execute(1, 'page');
    }

    public function testUnknownTypeIsRefusedBeforeCompiling(): void
    {
        $compiler = $this->createMock(TypoScriptCompilerService::class);
        $compiler->expects(self::never())->method('compile');

        $this->expectException(ToolCallException::class);

        $this->createTool($compiler)->execute(1, 'page');
    }

    public function testRefusesWithoutReadAccess(): void
    {
        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('canSelectTable')->willReturn(false);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Access denied');

        $this->createTool(null, $permissionService)->execute(1);
    }

    private function createTool(
        ?TypoScriptCompilerService $compiler = null,
        ?PermissionService $permissionService = null,
    ): TypoScriptActiveTool {
        if ($compiler === null) {
            $compiler = $this->createStub(TypoScriptCompilerService::class);
            $compiler->method('compile')->willReturn(self::COMPILED);
        }

        if ($permissionService === null) {
            $permissionService = $this->createStub(PermissionService::class);
            $permissionService->method('canSelectTable')->willReturn(true);
        }

        return new TypoScriptActiveTool($compiler, $permissionService);
    }
}
