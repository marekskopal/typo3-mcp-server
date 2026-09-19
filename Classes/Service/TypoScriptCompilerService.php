<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use Mcp\Exception\ToolCallException;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Exception\Page\RootLineException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Resolves and compiles the TypoScript that applies to a page.
 *
 * Named `TypoScriptCompilerService` rather than `TypoScriptService` on purpose: core already has a
 * `TYPO3\CMS\Core\TypoScript\TypoScriptService` (the plain-array converter), and two classes with
 * the same short name on opposite sides of a `use` statement is a reading trap.
 *
 * The compile sequence is copied from `TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager`,
 * which is core's own non-frontend caller of the same API: rootline, sys_template rows, then the
 * two `FrontendTypoScriptFactory` calls. Most of what it touches is marked `@internal` in core —
 * accepted knowingly, because there is no public alternative and shipped core depends on exactly
 * this surface.
 *
 * Since TYPO3 v13.1 a site and its sets provide TypoScript too, applied before any `sys_template`
 * row and always as a new root scope, which is why the site is resolved here rather than the
 * sys_template rows being read on their own.
 *
 * **Rootline shape.** `RootlineUtility::get()` returns the pages keyed by depth and `krsort()`ed:
 * iteration runs from the requested page up to the site root, but key `0` is the *root*. Nothing
 * here may index it — `reset()` is the requested page, and `ksort()` on a copy gives the top-down
 * order the condition matcher wants for `fullRootLine`. Truncation keeps the keys for the same reason.
 */
readonly class TypoScriptCompilerService
{
    /**
     * Copied from `BackendConfigurationManager::getTypoScriptSetup()`. A root row with `clear = 3`
     * and empty source contributes nothing of its own but makes the tree builder load the global
     * TypoScript, which is what a page with no template of its own still renders with.
     */
    private const array GLOBAL_ONLY_TEMPLATE_ROW = [
        'uid' => 0,
        'pid' => 0,
        'title' => 'Fake sys_template row to force global TypoScript loading',
        'root' => 1,
        'clear' => 3,
        'include_static_file' => '',
        'basedOn' => '',
        'includeStaticAfterBasedOn' => 0,
        'static_file_mode' => false,
        'constants' => '',
        'config' => '',
        'deleted' => 0,
        'hidden' => 0,
        'starttime' => 0,
        'endtime' => 0,
        'sorting' => 0,
    ];

    public function __construct(
        private FrontendTypoScriptFactory $frontendTypoScriptFactory,
        private SysTemplateRepository $sysTemplateRepository,
        private SiteFinder $siteFinder,
        private SetRegistry $setRegistry,
        private PhpFrontend $typoScriptCache,
    ) {
    }

    /**
     * Which templates apply to a page, in the order they are evaluated.
     *
     * @return array{
     *     pageId: int,
     *     siteIdentifier: string|null,
     *     siteRootPageId: int|null,
     *     sets: list<string>,
     *     rootline: list<array{uid: int, title: string}>,
     *     templates: list<array{
     *         uid: int, pid: int, title: string, root: int, clear: int, hidden: int,
     *         basedOn: string, include_static_file: string, includeStaticAfterBasedOn: int,
     *         hasConstants: bool, hasSetup: bool,
     *     }>,
     * }
     */
    public function resolveTemplateChain(int $pageId): array
    {
        [$rootline, $site, $sysTemplateRows] = $this->resolve($pageId);

        // Iteration order, requested page first — see the class docblock on why not the keys.
        $pages = [];
        foreach ($rootline as $page) {
            $pages[] = ['uid' => $this->intField($page, 'uid'), 'title' => $this->stringField($page, 'title')];
        }

        // Deliberately a summary, not the rows: `constants` and `config` hold the full TypoScript
        // source and would dwarf everything else here. Read them with typoscript_get.
        $templates = [];
        foreach ($sysTemplateRows as $row) {
            $templates[] = [
                'uid' => $this->intField($row, 'uid'),
                'pid' => $this->intField($row, 'pid'),
                'title' => $this->stringField($row, 'title'),
                'root' => $this->intField($row, 'root'),
                'clear' => $this->intField($row, 'clear'),
                'hidden' => $this->intField($row, 'hidden'),
                'basedOn' => $this->stringField($row, 'basedOn'),
                'include_static_file' => $this->stringField($row, 'include_static_file'),
                'includeStaticAfterBasedOn' => $this->intField($row, 'includeStaticAfterBasedOn'),
                'hasConstants' => $this->stringField($row, 'constants') !== '',
                'hasSetup' => $this->stringField($row, 'config') !== '',
            ];
        }

        return [
            'pageId' => $pageId,
            'siteIdentifier' => $site instanceof Site ? $site->getIdentifier() : null,
            'siteRootPageId' => $site instanceof Site ? $site->getRootPageId() : null,
            'sets' => $site instanceof Site ? $site->getSets() : [],
            'rootline' => $pages,
            'templates' => $templates,
        ];
    }

    /**
     * The effective constants and setup for a page, as flat dotted maps
     * (`lib.contentElement.templateName => Default`).
     *
     * Conditions are evaluated with no HTTP request, so a condition reading the request
     * (`[request.getQueryParams()...]`) falls to its default branch. Everything else — workspace,
     * backend user, site, page, rootline — matches what the frontend would resolve.
     *
     * @return array{constants: array<string, string>, setup: array<string, string>}
     */
    public function compile(int $pageId): array
    {
        [$rootline, $site, $sysTemplateRows] = $this->resolve($pageId);

        // With neither a sys_template row nor a site set there is nothing to include, and the
        // compile would answer `{}` — not "no TypoScript applies" but "global TypoScript was never
        // loaded". Core fakes a root row for exactly this case; mirror it, so a page without a
        // template still reports the global TypoScript it would render with. Like core, ask the
        // registry for the *resolved* sets: a set named in config.yaml whose extension is gone
        // contributes nothing.
        if ($sysTemplateRows === [] && $this->resolvedSets($site) === []) {
            $sysTemplateRows[] = self::GLOBAL_ONLY_TEMPLATE_ROW;
        }

        $topDownRootline = $rootline;
        ksort($topDownRootline);

        $requestedPage = reset($rootline);

        $expressionMatcherVariables = [
            'request' => null,
            'pageId' => $pageId,
            // The frontend passes the requested page here (PrepareTypoScriptFrontendRendering), so
            // `[page["uid"] == 12]` resolves as it would when page 12 renders.
            'page' => is_array($requestedPage) ? $requestedPage : [],
            'fullRootLine' => $topDownRootline,
            'site' => $site,
        ];

        $frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
            $site,
            $sysTemplateRows,
            $expressionMatcherVariables,
            $this->typoScriptCache,
        );

        $frontendTypoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
            true,
            $frontendTypoScript,
            $site,
            $sysTemplateRows,
            $expressionMatcherVariables,
            '0',
            $this->typoScriptCache,
            null,
        );

        return [
            'constants' => $this->toFlatStringMap($frontendTypoScript->getFlatSettings()),
            'setup' => $this->toFlatStringMap($frontendTypoScript->getSetupTree()->flatten()),
        ];
    }

    /**
     * Rootline (depth-keyed, requested page first in iteration), site and the sys_template rows
     * that apply, site-root first.
     *
     * @return array{array<int, array<string, mixed>>, SiteInterface, list<array<string, mixed>>}
     */
    private function resolve(int $pageId): array
    {
        $site = $this->resolveSite($pageId);
        $rootline = $this->buildRootline($pageId);

        // A site that is a TypoScript root does not inherit from pages above it, so the chain must
        // not reach past the site root either. Keys are kept, as core does.
        if ($site instanceof Site && $site->isTypoScriptRoot()) {
            $truncated = [];
            foreach ($rootline as $depth => $page) {
                $truncated[$depth] = $page;
                if ($this->intField($page, 'uid') === $site->getRootPageId()) {
                    break;
                }
            }

            $rootline = $truncated;
        }

        // Two arguments only: v14 added a trailing `?VisibilityAspect`, v13 has no such parameter.
        /** @var list<array<string, mixed>> $sysTemplateRows */
        $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootline($rootline, null);

        return [$rootline, $site, $sysTemplateRows];
    }

    /** @return array<int, array<string, mixed>> */
    private function buildRootline(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        try {
            /** @var array<int, array<string, mixed>> $rootline */
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();
        } catch (RootLineException $e) {
            // Unknown or deleted page, or a rootline that does not reach pid 0. A lookup whose
            // target does not exist is a refusal the client can act on, not an internal error.
            throw new ToolCallException('Page ' . $pageId . ' not found or its rootline is broken.', 0, $e);
        }

        return $rootline;
    }

    private function resolveSite(int $pageId): SiteInterface
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            // A page outside any configured site still has sys_template records above it.
            return new NullSite();
        }
    }

    /** @return list<mixed> */
    private function resolvedSets(SiteInterface $site): array
    {
        if (!$site instanceof Site) {
            return [];
        }

        return $this->setRegistry->getSets(...$site->getSets());
    }

    /** @param array<string, mixed> $row */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $row */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Core types both flattened maps as a bare `array`. Normalize to string keys and values so the
     * payload is predictable and PHPStan sees a real shape. A top-level numeric object (`10 = TEXT`)
     * arrives as an int key, because PHP converts a numeric-string key on write; it is a path too.
     *
     * @param array<mixed> $flattened
     * @return array<string, string>
     */
    private function toFlatStringMap(array $flattened): array
    {
        $result = [];
        foreach ($flattened as $path => $value) {
            $result[(string) $path] = is_scalar($value) ? (string) $value : '';
        }

        return $result;
    }
}
