<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Controller;

use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use const JSON_THROW_ON_ERROR;

#[AsController]
readonly class OAuthClientController
{
    private const string TABLE = 'tx_msmcpserver_oauth_client';

    private const string AUTHORIZATION_TABLE = 'tx_msmcpserver_oauth_authorization';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ConnectionPool $connectionPool,
        private FlashMessageService $flashMessageService,
        private UriBuilder $uriBuilder,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $clients = $queryBuilder
            ->select('c.uid', 'c.client_id', 'c.client_name', 'c.redirect_uris', 'c.hidden', 'c.crdate', 'u.username')
            ->from(self::TABLE, 'c')
            ->leftJoin('c', 'be_users', 'u', $queryBuilder->expr()->eq('c.be_user', $queryBuilder->quoteIdentifier('u.uid')))
            ->where($queryBuilder->expr()->eq('c.deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)))
            ->orderBy('c.crdate', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $beUsersQueryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $beUsers = $beUsersQueryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $beUsersQueryBuilder->expr()->eq('disable', $beUsersQueryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $moduleTemplate->assignMultiple([
            'clients' => $clients,
            'beUsers' => $beUsers,
        ]);

        return $moduleTemplate->renderResponse('OAuthClient/Index');
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string|int> $body */
        $body = $request->getParsedBody() ?? [];
        $clientName = is_string($body['client_name'] ?? null) ? trim((string) $body['client_name']) : '';
        $redirectUris = is_string($body['redirect_uris'] ?? null) ? trim((string) $body['redirect_uris']) : '';

        if ($clientName === '' || $redirectUris === '') {
            $this->addFlashMessage('Client name and redirect URIs are required.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $uriList = array_values(array_filter(array_map('trim', explode("\n", $redirectUris))));
        $clientId = bin2hex(random_bytes(16));

        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => json_encode($uriList, JSON_THROW_ON_ERROR),
            'be_user' => (int) ($body['be_user'] ?? 0),
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        $this->addFlashMessage(
            sprintf('OAuth client created. Client ID: %s', $clientId),
            ContextualFeedbackSeverity::OK,
        );

        return $this->redirect();
    }

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $queryParams */
        $queryParams = $request->getQueryParams();
        $uid = (int) ($queryParams['uid'] ?? 0);

        if ($uid === 0) {
            $this->addFlashMessage('Invalid client uid.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        /** @var array{uid: int|string, client_id: string, client_name: string, redirect_uris: string, be_user: int|string, hidden: int|string}|false $client */
        $client = $queryBuilder
            ->select('uid', 'client_id', 'client_name', 'redirect_uris', 'be_user', 'hidden')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($client === false) {
            $this->addFlashMessage('Client not found.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        /** @var list<string> $redirectUris */
        $redirectUris = json_decode($client['redirect_uris'], true, 16, JSON_THROW_ON_ERROR);
        $client['redirect_uris_text'] = implode("\n", $redirectUris);

        $tokensQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::AUTHORIZATION_TABLE);
        $tokensQueryBuilder->getRestrictions()->removeAll();
        $tokens = $tokensQueryBuilder
            ->select('a.uid', 'a.access_token_expires', 'a.refresh_token_expires', 'a.revoked', 'u.username')
            ->from(self::AUTHORIZATION_TABLE, 'a')
            ->leftJoin('a', 'be_users', 'u', $tokensQueryBuilder->expr()->eq(
                'a.be_user',
                $tokensQueryBuilder->quoteIdentifier('u.uid'),
            ))
            ->where(
                $tokensQueryBuilder->expr()->eq(
                    'a.client_id',
                    $tokensQueryBuilder->createNamedParameter($client['client_id']),
                ),
                $tokensQueryBuilder->expr()->eq(
                    'a.revoked',
                    $tokensQueryBuilder->createNamedParameter(0, ParameterType::INTEGER),
                ),
                $tokensQueryBuilder->expr()->neq('a.access_token_hash', $tokensQueryBuilder->createNamedParameter('')),
            )
            ->orderBy('a.access_token_expires', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $beUsersQueryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $beUsers = $beUsersQueryBuilder
            ->select('uid', 'username')
            ->from('be_users')
            ->where(
                $beUsersQueryBuilder->expr()->eq(
                    'disable',
                    $beUsersQueryBuilder->createNamedParameter(0, ParameterType::INTEGER),
                ),
            )
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->assignMultiple([
            'client' => $client,
            'tokens' => $tokens,
            'beUsers' => $beUsers,
            'now' => time(),
        ]);

        return $moduleTemplate->renderResponse('OAuthClient/Edit');
    }

    public function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string|int> $body */
        $body = $request->getParsedBody() ?? [];
        $uid = (int) ($body['uid'] ?? 0);

        if ($uid === 0) {
            $this->addFlashMessage('Invalid client uid.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $clientName = is_string($body['client_name'] ?? null) ? trim((string) $body['client_name']) : '';
        $redirectUris = is_string($body['redirect_uris'] ?? null) ? trim((string) $body['redirect_uris']) : '';

        if ($clientName === '' || $redirectUris === '') {
            $this->addFlashMessage('Client name and redirect URIs are required.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $uriList = array_values(array_filter(array_map('trim', explode("\n", $redirectUris))));

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, [
            'client_name' => $clientName,
            'redirect_uris' => json_encode($uriList, JSON_THROW_ON_ERROR),
            'be_user' => (int) ($body['be_user'] ?? 0),
        ], ['uid' => $uid]);

        $this->addFlashMessage('OAuth client updated.', ContextualFeedbackSeverity::OK);

        return $this->redirect();
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string|int> $body */
        $body = $request->getParsedBody() ?? [];
        $uid = (int) ($body['uid'] ?? 0);

        if ($uid === 0) {
            $this->addFlashMessage('Invalid client uid.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(self::TABLE, ['deleted' => 1], ['uid' => $uid]);

        $this->addFlashMessage('OAuth client deleted.', ContextualFeedbackSeverity::OK);

        return $this->redirect();
    }

    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string|int> $body */
        $body = $request->getParsedBody() ?? [];
        $uid = (int) ($body['uid'] ?? 0);
        $clientUid = (int) ($body['client_uid'] ?? 0);

        if ($uid === 0) {
            $this->addFlashMessage('Invalid token.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $connection = $this->connectionPool->getConnectionForTable(self::AUTHORIZATION_TABLE);
        $connection->update(self::AUTHORIZATION_TABLE, ['revoked' => 1], ['uid' => $uid]);

        $this->addFlashMessage('Token revoked.', ContextualFeedbackSeverity::OK);

        if ($clientUid > 0) {
            $uri = (string) $this->uriBuilder->buildUriFromRoute('msmcpserver_oauth_clients.edit', ['uid' => $clientUid]);

            return $this->responseFactory->createResponse(303)->withHeader('Location', $uri);
        }

        return $this->redirect();
    }

    private function addFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = new FlashMessage($message, '', $severity, true);
        $this->flashMessageService->getMessageQueueByIdentifier('msmcpserver.oauth')->enqueue($flashMessage);
    }

    private function redirect(): ResponseInterface
    {
        $uri = (string) $this->uriBuilder->buildUriFromRoute('msmcpserver_oauth_clients');

        return $this->responseFactory->createResponse(303)->withHeader('Location', $uri);
    }
}
