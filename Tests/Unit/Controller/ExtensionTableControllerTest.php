<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Controller;

use MarekSkopal\MsMcpServer\Controller\ExtensionTableController;
use MarekSkopal\MsMcpServer\Repository\DiscoveredTableRepository;
use MarekSkopal\MsMcpServer\Service\ExtensionTableDiscoveryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;

#[CoversClass(ExtensionTableController::class)]
final class ExtensionTableControllerTest extends TestCase
{
    public function testDiscoverActionInsertsNewTablesAndRedirects(): void
    {
        $discoveryService = $this->createStub(ExtensionTableDiscoveryService::class);
        $discoveryService->method('discoverTables')->willReturn([
            'tx_news_domain_model_news' => ['label' => 'News', 'prefix' => 'news'],
            'tx_blog_domain_model_post' => ['label' => 'Blog Post', 'prefix' => 'blog_post'],
        ]);

        $repository = $this->createMock(DiscoveredTableRepository::class);
        $repository->expects(self::exactly(2))
            ->method('insertIfNew')
            ->willReturnOnConsecutiveCalls(true, true);

        $controller = $this->createController(discoveryService: $discoveryService, repository: $repository);
        $response = $controller->discoverAction($this->createStub(ServerRequestInterface::class));

        self::assertSame(303, $response->getStatusCode());
    }

    public function testDiscoverActionShowsInfoWhenNoNewTablesFound(): void
    {
        $discoveryService = $this->createStub(ExtensionTableDiscoveryService::class);
        $discoveryService->method('discoverTables')->willReturn([
            'tx_news_domain_model_news' => ['label' => 'News', 'prefix' => 'news'],
        ]);

        $repository = $this->createStub(DiscoveredTableRepository::class);
        $repository->method('insertIfNew')->willReturn(false);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'No new')));

        $controller = $this->createController(
            discoveryService: $discoveryService,
            repository: $repository,
            flashMessageQueue: $flashMessageQueue,
        );
        $controller->discoverAction($this->createStub(ServerRequestInterface::class));
    }

    public function testToggleActionTogglesEnabledState(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 5]);

        $repository = $this->createMock(DiscoveredTableRepository::class);
        $repository->method('findByUid')->with(5)->willReturn([
            'uid' => 5,
            'table_name' => 'tx_news_domain_model_news',
            'label' => 'News',
            'prefix' => 'news',
            'enabled' => 0,
        ]);
        $repository->expects(self::once())
            ->method('setEnabled')
            ->with(5, true);

        $controller = $this->createController(repository: $repository);
        $response = $controller->toggleAction($request);

        self::assertSame(303, $response->getStatusCode());
    }

    public function testToggleActionDisablesEnabledTable(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 5]);

        $repository = $this->createMock(DiscoveredTableRepository::class);
        $repository->method('findByUid')->with(5)->willReturn([
            'uid' => 5,
            'table_name' => 'tx_news_domain_model_news',
            'label' => 'News',
            'prefix' => 'news',
            'enabled' => 1,
        ]);
        $repository->expects(self::once())
            ->method('setEnabled')
            ->with(5, false);

        $controller = $this->createController(repository: $repository);
        $controller->toggleAction($request);
    }

    public function testToggleActionRejectsZeroUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 0]);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'Invalid')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->toggleAction($request);

        self::assertSame(303, $response->getStatusCode());
    }

    public function testToggleActionRejectsNotFoundTable(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 999]);

        $repository = $this->createStub(DiscoveredTableRepository::class);
        $repository->method('findByUid')->willReturn(null);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'not found')));

        $controller = $this->createController(repository: $repository, flashMessageQueue: $flashMessageQueue);
        $controller->toggleAction($request);
    }

    public function testEditActionRejectsZeroUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['uid' => '0']);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'Invalid')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->editAction($request);

        self::assertSame(303, $response->getStatusCode());
    }

    public function testEditActionRejectsNotFoundTable(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['uid' => '999']);

        $repository = $this->createStub(DiscoveredTableRepository::class);
        $repository->method('findByUid')->willReturn(null);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'not found')));

        $controller = $this->createController(repository: $repository, flashMessageQueue: $flashMessageQueue);
        $controller->editAction($request);
    }

    public function testUpdateActionUpdatesAndRedirects(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'uid' => 5,
            'label' => 'Updated News',
            'prefix' => 'updated_news',
        ]);

        $repository = $this->createMock(DiscoveredTableRepository::class);
        $repository->expects(self::once())
            ->method('update')
            ->with(5, 'Updated News', 'updated_news');

        $controller = $this->createController(repository: $repository);
        $response = $controller->updateAction($request);

        self::assertSame(303, $response->getStatusCode());
    }

    public function testUpdateActionRejectsZeroUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 0, 'label' => 'Test', 'prefix' => 'test']);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'Invalid')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $controller->updateAction($request);
    }

    public function testUpdateActionRejectsEmptyLabel(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 5, 'label' => '', 'prefix' => 'test']);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'required')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $controller->updateAction($request);
    }

    public function testUpdateActionRejectsEmptyPrefix(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 5, 'label' => 'Test', 'prefix' => '']);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'required')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $controller->updateAction($request);
    }

    private function createController(
        ?ExtensionTableDiscoveryService $discoveryService = null,
        ?DiscoveredTableRepository $repository = null,
        ?FlashMessageQueue $flashMessageQueue = null,
        ?UriBuilder $uriBuilder = null,
    ): ExtensionTableController {
        $flashMessageService = $this->createStub(FlashMessageService::class);
        $flashMessageService->method('getMessageQueueByIdentifier')
            ->willReturn($flashMessageQueue ?? $this->createStub(FlashMessageQueue::class));

        $resolvedUriBuilder = $uriBuilder ?? $this->createStub(UriBuilder::class);
        $resolvedUriBuilder->method('buildUriFromRoute')->willReturn('https://example.com/module');

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $response->method('getStatusCode')->willReturn(303);

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        return new ExtensionTableController(
            $this->createStub(ModuleTemplateFactory::class),
            $discoveryService ?? $this->createStub(ExtensionTableDiscoveryService::class),
            $repository ?? $this->createStub(DiscoveredTableRepository::class),
            $flashMessageService,
            $resolvedUriBuilder,
            $responseFactory,
        );
    }
}
