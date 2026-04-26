<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Controller;

use MarekSkopal\MsMcpServer\Repository\DiscoveredTableRepository;
use MarekSkopal\MsMcpServer\Service\ExtensionTableDiscoveryService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
readonly class ExtensionTableController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ExtensionTableDiscoveryService $discoveryService,
        private DiscoveredTableRepository $repository,
        private FlashMessageService $flashMessageService,
        private UriBuilder $uriBuilder,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $extconfTables = $this->getExtconfTables();
        $discoveredRows = $this->repository->findAll();

        /** @var list<array{tableName: string, label: string, prefix: string, source: string, enabled: bool, uid: int|null}> $tables */
        $tables = [];

        foreach ($extconfTables as $tableName => $config) {
            /** @var array{label?: string, prefix?: string} $configArray */
            $configArray = is_array($config) ? $config : [];
            $tables[] = [
                'tableName' => $tableName,
                'label' => is_string($configArray['label'] ?? null) ? $configArray['label'] : $tableName,
                'prefix' => is_string($configArray['prefix'] ?? null) ? $configArray['prefix'] : '',
                'source' => 'extconf',
                'enabled' => true,
                'uid' => null,
            ];
        }

        foreach ($discoveredRows as $row) {
            if (array_key_exists($row['table_name'], $extconfTables)) {
                continue;
            }

            $tables[] = [
                'tableName' => $row['table_name'],
                'label' => $row['label'],
                'prefix' => $row['prefix'],
                'source' => 'discovered',
                'enabled' => (int) $row['enabled'] === 1,
                'uid' => (int) $row['uid'],
            ];
        }

        $moduleTemplate->assignMultiple([
            'tables' => $tables,
        ]);

        return $moduleTemplate->renderResponse('ExtensionTable/Index');
    }

    public function discoverAction(ServerRequestInterface $request): ResponseInterface
    {
        $candidates = $this->discoveryService->discoverTables();

        $newCount = 0;
        foreach ($candidates as $tableName => $config) {
            $inserted = $this->repository->insertIfNew($tableName, $config['label'], $config['prefix']);
            if ($inserted) {
                $newCount++;
            }
        }

        if ($newCount > 0) {
            $this->addFlashMessage(
                sprintf('Discovered %d new extension table%s.', $newCount, $newCount > 1 ? 's' : ''),
                ContextualFeedbackSeverity::OK,
            );
        } else {
            $this->addFlashMessage('No new extension tables found.', ContextualFeedbackSeverity::INFO);
        }

        return $this->redirectToIndex();
    }

    public function toggleAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string|int> $body */
        $body = $request->getParsedBody() ?? [];
        $uid = (int) ($body['uid'] ?? 0);

        if ($uid === 0) {
            $this->addFlashMessage('Invalid table.', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToIndex();
        }

        $row = $this->repository->findByUid($uid);
        if ($row === null) {
            $this->addFlashMessage('Table not found.', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToIndex();
        }

        $newState = (int) $row['enabled'] !== 1;
        $this->repository->setEnabled($uid, $newState);

        $this->addFlashMessage(
            sprintf('Extension table "%s" %s.', $row['label'], $newState ? 'enabled' : 'disabled'),
            ContextualFeedbackSeverity::OK,
        );

        return $this->redirectToIndex();
    }

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string> $queryParams */
        $queryParams = $request->getQueryParams();
        $uid = (int) ($queryParams['uid'] ?? 0);

        if ($uid === 0) {
            $this->addFlashMessage('Invalid table.', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToIndex();
        }

        $row = $this->repository->findByUid($uid);
        if ($row === null) {
            $this->addFlashMessage('Table not found.', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToIndex();
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->assignMultiple([
            'table' => $row,
        ]);

        return $moduleTemplate->renderResponse('ExtensionTable/Edit');
    }

    public function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, string|int> $body */
        $body = $request->getParsedBody() ?? [];
        $uid = (int) ($body['uid'] ?? 0);

        if ($uid === 0) {
            $this->addFlashMessage('Invalid table.', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToIndex();
        }

        $label = is_string($body['label'] ?? null) ? trim((string) $body['label']) : '';
        $prefix = is_string($body['prefix'] ?? null) ? trim((string) $body['prefix']) : '';

        if ($label === '' || $prefix === '') {
            $this->addFlashMessage('Label and prefix are required.', ContextualFeedbackSeverity::ERROR);

            return $this->redirectToIndex();
        }

        $this->repository->update($uid, $label, $prefix);

        $this->addFlashMessage('Extension table updated.', ContextualFeedbackSeverity::OK);

        return $this->redirectToIndex();
    }

    /** @return array<mixed> */
    private function getExtconfTables(): array
    {
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($typo3ConfVars)) {
            return [];
        }

        $extConf = $typo3ConfVars['EXTCONF'] ?? [];
        if (!is_array($extConf)) {
            return [];
        }

        $msMcpServer = $extConf['ms_mcp_server'] ?? [];
        if (!is_array($msMcpServer)) {
            return [];
        }

        $tables = $msMcpServer['tables'] ?? [];

        return is_array($tables) ? $tables : [];
    }

    private function addFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = new FlashMessage($message, '', $severity, true);
        $this->flashMessageService->getMessageQueueByIdentifier('msmcpserver.extensions')->enqueue($flashMessage);
    }

    private function redirectToIndex(): ResponseInterface
    {
        $uri = (string) $this->uriBuilder->buildUriFromRoute('msmcpserver_oauth_clients.extensions');

        return $this->responseFactory->createResponse(303)->withHeader('Location', $uri);
    }
}
