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

#[AsController]
final readonly class TokenManagementController
{
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

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_msmcpserver_token');
        $tokens = $queryBuilder
            ->select('t.uid', 't.name', 't.expires', 't.hidden', 't.crdate', 'u.username')
            ->from('tx_msmcpserver_token', 't')
            ->leftJoin('t', 'be_users', 'u', $queryBuilder->expr()->eq('t.be_user', $queryBuilder->quoteIdentifier('u.uid')))
            ->where($queryBuilder->expr()->eq('t.deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)))
            ->orderBy('t.crdate', 'DESC')
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
            'tokens' => $tokens,
            'beUsers' => $beUsers,
        ]);

        return $moduleTemplate->renderResponse('TokenManagement/Index');
    }

    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string|int> $body */
        $body = $request->getParsedBody() ?? [];
        $name = is_string($body['name'] ?? null) ? trim((string) $body['name']) : '';
        $beUser = (int) ($body['be_user'] ?? 0);

        if ($name === '' || $beUser === 0) {
            $this->addFlashMessage('Name and backend user are required.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);

        $connection = $this->connectionPool->getConnectionForTable('tx_msmcpserver_token');
        $connection->insert('tx_msmcpserver_token', [
            'name' => $name,
            'token_hash' => $tokenHash,
            'be_user' => $beUser,
            'expires' => (int) ($body['expires'] ?? 0),
            'crdate' => time(),
            'tstamp' => time(),
            'pid' => 0,
        ]);

        $this->addFlashMessage(
            sprintf('Token created. Copy it now, it will not be shown again: %s', $plainToken),
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
            $this->addFlashMessage('Invalid token uid.', ContextualFeedbackSeverity::ERROR);

            return $this->redirect();
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_msmcpserver_token');
        $connection->update('tx_msmcpserver_token', ['deleted' => 1], ['uid' => $uid]);

        $this->addFlashMessage('Token deleted.', ContextualFeedbackSeverity::OK);

        return $this->redirect();
    }

    private function addFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = new FlashMessage($message, '', $severity, true);
        $this->flashMessageService->getMessageQueueByIdentifier('msmcpserver.tokens')->enqueue($flashMessage);
    }

    private function redirect(): ResponseInterface
    {
        $uri = (string) $this->uriBuilder->buildUriFromRoute('msmcpserver_tokens');

        return $this->responseFactory->createResponse(303)->withHeader('Location', $uri);
    }
}
