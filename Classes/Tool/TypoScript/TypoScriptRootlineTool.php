<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\TypoScript;

use MarekSkopal\MsMcpServer\Service\PermissionService;
use MarekSkopal\MsMcpServer\Service\TypoScriptCompilerService;
use MarekSkopal\MsMcpServer\Tool\Result\TypoScriptRootlineResult;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

readonly class TypoScriptRootlineTool
{
    public function __construct(private TypoScriptCompilerService $typoScriptCompilerService, private PermissionService $permissionService,)
    {
    }

    #[McpTool(
        name: 'typoscript_rootline',
        description: 'Explain which TypoScript sources apply to a page and in what order: the page rootline,'
            . ' the site and its sets (a site always opens a new root scope), and every sys_template record'
            . ' that applies, site root first, with its root/clear flags, basedOn chain and static includes.'
            . ' Answers "why does this page get this TypoScript". Use typoscript_get to read a template\'s source,'
            . ' or typoscript_active for the compiled result.',
    )]
    public function execute(int $pageId): TypoScriptRootlineResult
    {
        if (!$this->permissionService->canSelectTable('sys_template')) {
            throw new ToolCallException('Access denied: you may not read TypoScript templates (sys_template).');
        }

        $chain = $this->typoScriptCompilerService->resolveTemplateChain($pageId);

        return new TypoScriptRootlineResult(
            $chain['pageId'],
            $chain['siteIdentifier'],
            $chain['siteRootPageId'],
            $chain['sets'],
            $chain['rootline'],
            $chain['templates'],
        );
    }
}
