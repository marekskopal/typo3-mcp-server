<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Controller;

use MarekSkopal\MsMcpServer\Controller\OAuthClientController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;

#[CoversClass(OAuthClientController::class)]
final class OAuthClientControllerTest extends TestCase
{
    public function testCreateActionInsertsClientAndRedirects(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'client_name' => 'Test Client',
            'redirect_uris' => "https://example.com/callback\nhttps://example.com/callback2",
            'be_user' => 1,
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_msmcpserver_oauth_client',
                self::callback(function (array $data): bool {
                    self::assertSame('Test Client', $data['client_name']);
                    self::assertSame(1, $data['be_user']);
                    self::assertSame(32, strlen($data['client_id']));

                    /** @var list<string> $uris */
                    $uris = json_decode($data['redirect_uris'], true, 16, JSON_THROW_ON_ERROR);
                    self::assertSame(['https://example.com/callback', 'https://example.com/callback2'], $uris);

                    return true;
                }),
            );

        $controller = $this->createController(connection: $connection);
        $response = $controller->createAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testCreateActionRejectsEmptyClientName(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'client_name' => '',
            'redirect_uris' => 'https://example.com/callback',
        ]);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'required')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->createAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testCreateActionRejectsEmptyRedirectUris(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'client_name' => 'Test',
            'redirect_uris' => '',
        ]);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'required')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->createAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testCreateActionFiltersEmptyRedirectUriLines(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'client_name' => 'Test Client',
            'redirect_uris' => "https://example.com/callback\n\n  \nhttps://example.com/callback2",
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_msmcpserver_oauth_client',
                self::callback(function (array $data): bool {
                    /** @var list<string> $uris */
                    $uris = json_decode($data['redirect_uris'], true, 16, JSON_THROW_ON_ERROR);
                    self::assertSame(['https://example.com/callback', 'https://example.com/callback2'], $uris);

                    return true;
                }),
            );

        $controller = $this->createController(connection: $connection);
        $controller->createAction($request);
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

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testEditActionRejectsClientNotFound(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['uid' => '5']);

        $queryBuilder = $this->createQueryBuilderStub();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($this->createFetchAssociativeResult(false));

        $connectionPool = $this->createStub(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'not found')));

        $controller = $this->createController(connectionPool: $connectionPool, flashMessageQueue: $flashMessageQueue);
        $response = $controller->editAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testUpdateActionUpdatesClientAndRedirects(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'uid' => 5,
            'client_name' => 'Updated Client',
            'redirect_uris' => 'https://example.com/new-callback',
            'be_user' => 2,
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'tx_msmcpserver_oauth_client',
                self::callback(function (array $data): bool {
                    self::assertSame('Updated Client', $data['client_name']);
                    self::assertSame(2, $data['be_user']);

                    /** @var list<string> $uris */
                    $uris = json_decode($data['redirect_uris'], true, 16, JSON_THROW_ON_ERROR);
                    self::assertSame(['https://example.com/new-callback'], $uris);

                    return true;
                }),
                ['uid' => 5],
            );

        $controller = $this->createController(connection: $connection);
        $response = $controller->updateAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testUpdateActionRejectsZeroUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'uid' => 0,
            'client_name' => 'Test',
            'redirect_uris' => 'https://example.com/callback',
        ]);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'Invalid')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->updateAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testUpdateActionRejectsMissingFields(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([
            'uid' => 5,
            'client_name' => '',
            'redirect_uris' => 'https://example.com/callback',
        ]);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'required')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->updateAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testDeleteActionSoftDeletesClient(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 5]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with('tx_msmcpserver_oauth_client', ['deleted' => 1], ['uid' => 5]);

        $controller = $this->createController(connection: $connection);
        $response = $controller->deleteAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testDeleteActionRejectsZeroUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 0]);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'Invalid')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->deleteAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testRevokeTokenActionRevokesTokenAndRedirectsToIndex(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 10, 'client_uid' => 0]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with('tx_msmcpserver_oauth_authorization', ['revoked' => 1], ['uid' => 10]);

        $controller = $this->createController(connection: $connection);
        $response = $controller->revokeTokenAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testRevokeTokenActionRedirectsToEditWhenClientUidProvided(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 10, 'client_uid' => 5]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('update')
            ->with('tx_msmcpserver_oauth_authorization', ['revoked' => 1], ['uid' => 10]);

        $uriBuilder = $this->createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')
            ->willReturn('https://example.com/edit?uid=5');

        $controller = $this->createController(connection: $connection, uriBuilder: $uriBuilder);
        $response = $controller->revokeTokenAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    public function testRevokeTokenActionRejectsZeroUid(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => 0]);

        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(fn (FlashMessage $msg): bool => str_contains($msg->getMessage(), 'Invalid')));

        $controller = $this->createController(flashMessageQueue: $flashMessageQueue);
        $response = $controller->revokeTokenAction($request);

        self::assertSame(303, $this->getResponseStatusCode($response));
    }

    private function createController(
        ?ConnectionPool $connectionPool = null,
        ?Connection $connection = null,
        ?FlashMessageQueue $flashMessageQueue = null,
        ?UriBuilder $uriBuilder = null,
    ): OAuthClientController {
        $resolvedConnectionPool = $connectionPool ?? $this->createStub(ConnectionPool::class);

        if ($connection !== null) {
            $resolvedConnectionPool = $this->createStub(ConnectionPool::class);
            $resolvedConnectionPool->method('getConnectionForTable')->willReturn($connection);
        }

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

        return new OAuthClientController(
            $this->createStub(ModuleTemplateFactory::class),
            $resolvedConnectionPool,
            $flashMessageService,
            $resolvedUriBuilder,
            $responseFactory,
        );
    }

    private function getResponseStatusCode(ResponseInterface $response): int
    {
        return $response->getStatusCode();
    }

    /** @return QueryBuilder&\PHPUnit\Framework\MockObject\Stub */
    private function createQueryBuilderStub(): QueryBuilder
    {
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $expressionBuilder = $this->createStub(ExpressionBuilder::class);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn("'0'");
        $queryBuilder->method('quoteIdentifier')->willReturn('"u"."uid"');

        return $queryBuilder;
    }

    /** @return \Doctrine\DBAL\Result&\PHPUnit\Framework\MockObject\Stub */
    private function createFetchAssociativeResult(array|false $data): \Doctrine\DBAL\Result
    {
        $result = $this->createStub(\Doctrine\DBAL\Result::class);
        $result->method('fetchAssociative')->willReturn($data);

        return $result;
    }
}
