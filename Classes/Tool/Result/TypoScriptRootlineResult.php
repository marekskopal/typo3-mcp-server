<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tool\Result;

/**
 * Which TypoScript sources apply to a page and in what order: the site and its sets first (a site
 * always opens a new root scope since v13.1), then the sys_template records up the rootline.
 */
readonly class TypoScriptRootlineResult
{
    /**
     * @param list<string> $sets site sets providing TypoScript, applied before any sys_template row
     * @param list<array{uid: int, title: string}> $rootline the page and its ancestors, deepest first
     * @param list<array{
     *     uid: int, pid: int, title: string, root: int, clear: int, hidden: int,
     *     basedOn: string, include_static_file: string, includeStaticAfterBasedOn: int,
     *     hasConstants: bool, hasSetup: bool,
     * }> $templates sys_template records in evaluation order, site root first
     */
    public function __construct(
        public int $pageId,
        public ?string $siteIdentifier,
        public ?int $siteRootPageId,
        public array $sets,
        public array $rootline,
        public array $templates,
    ) {
    }
}
