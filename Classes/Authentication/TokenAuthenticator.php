<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Authentication;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class TokenAuthenticator
{
    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    public function authenticate(string $token): int
    {
        if ($token === '') {
            throw new \RuntimeException('Empty token provided', 1712000001);
        }

        $tokenHash = hash('sha256', $token);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_msmcpserver_token');
        /** @var array{be_user: int|string, expires: int|string, hidden: int|string}|false $row */
        $row = $queryBuilder
            ->select('be_user', 'expires', 'hidden')
            ->from('tx_msmcpserver_token')
            ->where(
                $queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($tokenHash)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            throw new \RuntimeException('Invalid token', 1712000002);
        }

        if ((int) $row['hidden'] === 1) {
            throw new \RuntimeException('Token is disabled', 1712000003);
        }

        $expires = (int) $row['expires'];
        if ($expires > 0 && $expires < time()) {
            throw new \RuntimeException('Token has expired', 1712000004);
        }

        return (int) $row['be_user'];
    }
}
