<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Service;

use MarekSkopal\MsMcpServer\Service\TypoScriptCompilerService;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Set\SetDefinition;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

#[CoversClass(TypoScriptCompilerService::class)]
final class TypoScriptCompilerServiceTest extends TestCase
{
    private const array SUB = ['uid' => 12, 'title' => 'Sub'];
    private const array HOME = ['uid' => 1, 'title' => 'Home'];
    private const array ABOVE = ['uid' => 99, 'title' => 'Above the site'];

    /**
     * The shape RootlineUtility::get() really returns: keyed by depth and krsort()ed, so the
     * requested page comes first in iteration while key 0 is the topmost page. A test that fed a
     * plain list here would pass `$rootline[0]` as the requested page and never notice.
     */
    private const array ROOTLINE = [2 => self::SUB, 1 => self::HOME, 0 => self::ABOVE];

    private const array TEMPLATE_ROW = [
        'uid' => 3,
        'pid' => 1,
        'title' => 'Main',
        'root' => '1',
        'clear' => '3',
        'hidden' => '0',
        'basedOn' => '7,8',
        'include_static_file' => 'EXT:fluid_styled_content/Configuration/TypoScript/',
        'includeStaticAfterBasedOn' => '0',
        'constants' => 'foo = bar',
        'config' => '',
    ];

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
    }

    public function testResolveTemplateChainReportsSiteSetsAndTemplates(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([self::TEMPLATE_ROW]);

        $chain = $this->createService($repository, $this->createSite())->resolveTemplateChain(12);

        self::assertSame(12, $chain['pageId']);
        self::assertSame('main', $chain['siteIdentifier']);
        self::assertSame(1, $chain['siteRootPageId']);
        self::assertSame(['typo3/fluid-styled-content'], $chain['sets']);
        // Requested page first, as a list — the depth keys are an implementation detail of core.
        self::assertSame([self::SUB, self::HOME, self::ABOVE], $chain['rootline']);
        self::assertSame(3, $chain['templates'][0]['uid']);
        self::assertSame(1, $chain['templates'][0]['root']);
        self::assertSame('7,8', $chain['templates'][0]['basedOn']);
    }

    public function testResolveTemplateChainSummarisesSourceRatherThanReturningIt(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([self::TEMPLATE_ROW]);

        $chain = $this->createService($repository, $this->createSite())->resolveTemplateChain(12);
        $template = $chain['templates'][0];

        // constants/config hold the full TypoScript source and would dwarf the rest of the payload.
        self::assertArrayNotHasKey('constants', $template);
        self::assertArrayNotHasKey('config', $template);
        self::assertTrue($template['hasConstants']);
        self::assertFalse($template['hasSetup']);
    }

    public function testRootlineIsTruncatedAtTheSiteRootWhenTheSiteIsATypoScriptRoot(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createMock(SysTemplateRepository::class);
        $repository->expects(self::once())
            ->method('getSysTemplateRowsByRootline')
            // Page 99 sits above the site root, and a TypoScript-root site does not inherit from it.
            // Depth keys survive the cut, as they do in core.
            ->with([2 => self::SUB, 1 => self::HOME], null)
            ->willReturn([]);

        $this->createService($repository, $this->createSite(typoScriptRoot: true))->resolveTemplateChain(12);
    }

    public function testRootlineIsKeptWholeWhenTheSiteIsNotATypoScriptRoot(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createMock(SysTemplateRepository::class);
        $repository->expects(self::once())
            ->method('getSysTemplateRowsByRootline')
            ->with(self::ROOTLINE, null)
            ->willReturn([]);

        $this->createService($repository, $this->createSite())->resolveTemplateChain(12);
    }

    public function testPageOutsideAnySiteFallsBackToNullSite(): void
    {
        $this->stubRootline([0 => ['uid' => 5, 'title' => 'Orphan']]);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([]);

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('no site'));

        $chain = $this->createService($repository, null, $siteFinder)->resolveTemplateChain(5);

        self::assertNull($chain['siteIdentifier']);
        self::assertNull($chain['siteRootPageId']);
        self::assertSame([], $chain['sets']);
    }

    public function testPageZeroHasNoRootline(): void
    {
        $repository = $this->createMock(SysTemplateRepository::class);
        $repository->expects(self::once())
            ->method('getSysTemplateRowsByRootline')
            ->with([], null)
            ->willReturn([]);

        $chain = $this->createService($repository, $this->createSite())->resolveTemplateChain(0);

        self::assertSame([], $chain['rootline']);
    }

    public function testAnUnknownPageIsARefusalNotAnInternalError(): void
    {
        $rootlineUtility = $this->createStub(RootlineUtility::class);
        $rootlineUtility->method('get')->willThrowException(new PageNotFoundException('Broken rootline.', 1343464101));
        GeneralUtility::addInstance(RootlineUtility::class, $rootlineUtility);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Page 99999 not found');

        $this->createService($this->createStub(SysTemplateRepository::class), $this->createSite())->resolveTemplateChain(99999);
    }

    public function testCompileReturnsFlatConstantsAndSetup(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([self::TEMPLATE_ROW]);

        $factory = $this->createStub(FrontendTypoScriptFactory::class);
        $factory->method('createSettingsAndSetupConditions')->willReturn($this->createFrontendTypoScript());
        $factory->method('createSetupConfigOrFullSetup')->willReturn($this->createFrontendTypoScript(
            constants: ['styles.content.textmedia.maxW' => 600],
            setup: ['lib.contentElement.templateName' => 'Default', 10 => 'TEXT', '10.value' => 'x'],
        ));

        $compiled = $this->createService($repository, $this->createSite(), null, $factory)->compile(12);

        // Core types both maps as a bare array; the service normalizes to string keys and values.
        // A top-level numeric object arrives as an int key and is a path like any other.
        self::assertSame(['styles.content.textmedia.maxW' => '600'], $compiled['constants']);
        self::assertSame(
            ['lib.contentElement.templateName' => 'Default', '10' => 'TEXT', '10.value' => 'x'],
            $compiled['setup'],
        );
    }

    public function testCompileHandsTheConditionMatcherTheRequestedPageAndATopDownRootline(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([]);

        $factory = $this->createMock(FrontendTypoScriptFactory::class);
        $factory->expects(self::once())
            ->method('createSettingsAndSetupConditions')
            ->with(
                self::anything(),
                [],
                self::callback(static function (array $variables): bool {
                    // `page` is what the frontend passes: the page being rendered, not key 0 (the root).
                    // `fullRootLine` is top-down, which is what ksort() on the depth keys yields.
                    return $variables['pageId'] === 12
                        && $variables['request'] === null
                        && $variables['page'] === self::SUB
                        && $variables['fullRootLine'] === [0 => self::ABOVE, 1 => self::HOME, 2 => self::SUB];
                }),
                self::anything(),
            )
            ->willReturn($this->createFrontendTypoScript());
        $factory->method('createSetupConfigOrFullSetup')->willReturn($this->createFrontendTypoScript());

        $this->createService($repository, $this->createSite(), null, $factory)->compile(12);
    }

    public function testCompileFakesARootRowWhenNothingWouldBeIncluded(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([]);

        // Without a row and without sets the tree builder includes nothing at all, so the answer
        // would be `{}` rather than the global TypoScript the page really renders with.
        $factory = $this->createMock(FrontendTypoScriptFactory::class);
        $factory->expects(self::once())
            ->method('createSettingsAndSetupConditions')
            ->with(
                self::anything(),
                self::callback(static fn(array $rows): bool => count($rows) === 1
                    && $rows[0]['uid'] === 0
                    && $rows[0]['root'] === 1
                    && $rows[0]['clear'] === 3),
                self::anything(),
                self::anything(),
            )
            ->willReturn($this->createFrontendTypoScript());
        $factory->method('createSetupConfigOrFullSetup')->willReturn($this->createFrontendTypoScript());

        $this->createService($repository, $this->createSite(sets: []), null, $factory)->compile(12);
    }

    public function testCompileFakesARootRowWhenTheConfiguredSetsDoNotResolve(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([]);

        // config.yaml names a set, but its extension is gone: core consults the registry, not the
        // names, and still loads global TypoScript. So do we.
        $setRegistry = $this->createStub(SetRegistry::class);
        $setRegistry->method('getSets')->willReturn([]);

        $factory = $this->createMock(FrontendTypoScriptFactory::class);
        $factory->expects(self::once())
            ->method('createSettingsAndSetupConditions')
            ->with(self::anything(), self::countOf(1), self::anything(), self::anything())
            ->willReturn($this->createFrontendTypoScript());
        $factory->method('createSetupConfigOrFullSetup')->willReturn($this->createFrontendTypoScript());

        $this->createService($repository, $this->createSite(sets: ['vendor/removed']), null, $factory, $setRegistry)->compile(12);
    }

    public function testCompileDoesNotFakeARowWhenTheSiteBringsSets(): void
    {
        $this->stubRootline(self::ROOTLINE);

        $repository = $this->createStub(SysTemplateRepository::class);
        $repository->method('getSysTemplateRowsByRootline')->willReturn([]);

        $factory = $this->createMock(FrontendTypoScriptFactory::class);
        $factory->expects(self::once())
            ->method('createSettingsAndSetupConditions')
            ->with(self::anything(), [], self::anything(), self::anything())
            ->willReturn($this->createFrontendTypoScript());
        $factory->method('createSetupConfigOrFullSetup')->willReturn($this->createFrontendTypoScript());

        $this->createService($repository, $this->createSite(), null, $factory)->compile(12);
    }

    /**
     * @param array<mixed> $constants
     * @param array<mixed> $setup
     */
    private function createFrontendTypoScript(array $constants = [], array $setup = []): FrontendTypoScript
    {
        $setupTree = $this->createStub(RootNode::class);
        $setupTree->method('flatten')->willReturn($setup);

        $frontendTypoScript = $this->createStub(FrontendTypoScript::class);
        $frontendTypoScript->method('getFlatSettings')->willReturn($constants);
        $frontendTypoScript->method('getSetupTree')->willReturn($setupTree);

        return $frontendTypoScript;
    }

    /** @param array<int, array<string, mixed>> $rootline */
    private function stubRootline(array $rootline): void
    {
        $rootlineUtility = $this->createStub(RootlineUtility::class);
        $rootlineUtility->method('get')->willReturn($rootline);

        GeneralUtility::addInstance(RootlineUtility::class, $rootlineUtility);
    }

    /** @param list<string> $sets */
    private function createSite(bool $typoScriptRoot = false, array $sets = ['typo3/fluid-styled-content']): Site
    {
        $site = $this->createStub(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getRootPageId')->willReturn(1);
        $site->method('getSets')->willReturn($sets);
        $site->method('isTypoScriptRoot')->willReturn($typoScriptRoot);

        return $site;
    }

    private function createService(
        SysTemplateRepository $repository,
        ?Site $site,
        ?SiteFinder $siteFinder = null,
        ?FrontendTypoScriptFactory $factory = null,
        ?SetRegistry $setRegistry = null,
    ): TypoScriptCompilerService {
        if ($siteFinder === null) {
            $siteFinder = $this->createStub(SiteFinder::class);
            if ($site !== null) {
                $siteFinder->method('getSiteByPageId')->willReturn($site);
            }
        }

        if ($setRegistry === null) {
            // By default every configured set name resolves, so the fake-row branch is not taken.
            $setRegistry = $this->createStub(SetRegistry::class);
            $setRegistry->method('getSets')->willReturnCallback(
                fn(string ...$names): array => array_map(
                    static fn(string $name): SetDefinition => new SetDefinition($name, $name, []),
                    $names,
                ),
            );
        }

        return new TypoScriptCompilerService(
            $factory ?? $this->createStub(FrontendTypoScriptFactory::class),
            $repository,
            $siteFinder,
            $setRegistry,
            $this->createStub(PhpFrontend::class),
        );
    }
}
