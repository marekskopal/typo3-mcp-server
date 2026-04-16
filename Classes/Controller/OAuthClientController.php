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
final readonly class OAuthClientController
{
    private const string TABLE = 'tx_msmcpserver_oauth_client';

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

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => json_encode($uriList, JSON_THROW_ON_ERROR),
            'be_user' => (int) ($body['be_user'] ?? 0),
            'crdate' => time(),
            'tstamp' => time(),
            'pid' => 0,
        ]);

        $this->addFlashMessage(
            sprintf('OAuth client created. Client ID: %s', $clientId),
            ContextualFeedbackSeverity::OK,
        );

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
