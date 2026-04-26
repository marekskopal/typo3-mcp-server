<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Authentication;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

readonly class BackendUserBootstrap
{
    public function __construct(private ConnectionPool $connectionPool, private LanguageServiceFactory $languageServiceFactory)
    {
    }

    public function bootstrap(int $beUserUid): BackendUserAuthentication
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, int|string|null>|false $userRow */
        $userRow = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($beUserUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if ($userRow === false) {
            throw new \RuntimeException('Backend user not found', 1712000010);
        }

        if ((int) ($userRow['deleted'] ?? 0) === 1) {
            throw new \RuntimeException('Backend user not found', 1712000010);
        }

        if ((int) ($userRow['disable'] ?? 0) === 1) {
            throw new \RuntimeException('Backend user not found', 1712000010);
        }

        $backendUser = new BackendUserAuthentication();
        // @phpstan-ignore property.internal
        $backendUser->user = $userRow;
        // @phpstan-ignore method.internal
        $backendUser->fetchGroupData();

        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUser);

        return $backendUser;
    }
}
