<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\TypoScript;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TypoScriptCompilerService;
use MarekSkopal\MsMcpServer\Tool\Result\TypoScriptCompiledResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use const ARRAY_FILTER_USE_KEY;

readonly class TypoScriptActiveTool
{
    /**
     * A full setup tree on a real site runs to tens of thousands of leaves, which no client wants
     * in one response. The cap is the same bargain RecordService strikes at 500 rows: answer what
     * fits and say plainly that it was cut, rather than truncate silently or refuse outright.
     */
    private const int MAX_ENTRIES = 2000;

    private const array TYPES = ['setup', 'constants', 'both'];

    public function __construct(private TypoScriptCompilerService $typoScriptCompilerService, private PermissionService $permissionService,)
    {
    }

    #[McpTool(
        name: 'typoscript_active',
        description: 'Read the effective, compiled TypoScript for a page as a flat map of dotted paths'
            . ' ("lib.contentElement.templateName": "Default"). Constants are already substituted and'
            . ' conditions resolved, so this is what the frontend would actually use — the equivalent of the'
            . ' backend\'s "Active TypoScript" view. Parameters: pageId; type ("setup" default, "constants" or'
            . ' "both"); path to return only one object path and its children (e.g. "lib.contentElement").'
            . ' Output is capped at 2000 entries per section; narrow with path when the result is truncated.'
            . ' Conditions are evaluated without an HTTP request, so request-dependent conditions take their'
            . ' default branch. Use typoscript_rootline to see which templates produced this.',
    )]
    public function execute(int $pageId, string $type = 'setup', string $path = ''): TypoScriptCompiledResult
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ToolCallException(
                'Unknown type "' . $type . '". Use one of: ' . implode(', ', self::TYPES) . '.',
            );
        }

        if (!$this->permissionService->canSelectTable('sys_template')) {
            throw new ToolCallException('Access denied: you may not read TypoScript templates (sys_template).');
        }

        $compiled = $this->typoScriptCompilerService->compile($pageId);
        $path = trim($path);

        $constants = null;
        $constantsTruncated = false;
        if ($type !== 'setup') {
            [$constants, $constantsTruncated] = $this->slice($compiled['constants'], $path);
        }

        $setup = null;
        $setupTruncated = false;
        if ($type !== 'constants') {
            [$setup, $setupTruncated] = $this->slice($compiled['setup'], $path);
        }

        return new TypoScriptCompiledResult($pageId, $path, $constants, $setup, $constantsTruncated || $setupTruncated);
    }

    /**
     * Filters a flat map to one object path and its children, then caps it.
     *
     * The compile itself is always whole-tree — TypoScript cannot be resolved partially, since a
     * value anywhere may reference a constant defined anywhere else. `path` narrows the answer,
     * not the work; the result is cached by core, so repeated calls with different paths are cheap.
     *
     * @param array<string, string> $flat
     * @return array{array<string, string>, bool}
     */
    private function slice(array $flat, string $path): array
    {
        if ($path !== '') {
            $prefix = $path . '.';
            $flat = array_filter(
                $flat,
                static fn(string $key): bool => $key === $path || str_starts_with($key, $prefix),
                ARRAY_FILTER_USE_KEY,
            );
        }

        if (count($flat) <= self::MAX_ENTRIES) {
            return [$flat, false];
        }

        return [array_slice($flat, 0, self::MAX_ENTRIES, true), true];
    }
}
