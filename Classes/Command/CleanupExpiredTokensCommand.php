<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Command;

use Doctrine\DBAL\ParameterType;
use MarekSkopal\MsMcpServer\OAuth\RateLimitService;
use MarekSkopal\MsMcpServer\Repository\McpSessionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(name: 'mcp:cleanup', description: 'Remove expired and revoked OAuth authorizations and stale MCP sessions')]
class CleanupExpiredTokensCommand extends Command
{
    private const string AUTHORIZATION_TABLE = 'tx_msmcpserver_oauth_authorization';

    private const int DEFAULT_SESSION_LIFETIME = 86400;

    private readonly int $sessionLifetime;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly RateLimitService $rateLimitService,
        private readonly McpSessionRepository $sessionRepository,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();

        $config = $extensionConfiguration->get('ms_mcp_server');
        $sessionLifetime = is_array($config) ? ($config['sessionLifetime'] ?? null) : null;
        $resolved = is_numeric($sessionLifetime) ? (int) $sessionLifetime : self::DEFAULT_SESSION_LIFETIME;
        $this->sessionLifetime = $resolved > 0 ? $resolved : self::DEFAULT_SESSION_LIFETIME;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deletedAuthorizations = $this->deleteExpiredAuthorizations();
        $io->writeln(sprintf('Deleted %d expired/revoked OAuth authorizations.', $deletedAuthorizations));

        $deletedSessions = $this->sessionRepository->deleteExpired(time() - $this->sessionLifetime);
        $io->writeln(sprintf('Deleted %d stale MCP sessions.', $deletedSessions));

        $deletedRateLimits = $this->rateLimitService->deleteExpiredEntries();
        $io->writeln(sprintf('Deleted %d expired rate limit entries.', $deletedRateLimits));

        $io->success('Cleanup completed.');

        return Command::SUCCESS;
    }

    private function deleteExpiredAuthorizations(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::AUTHORIZATION_TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $now = time();

        return (int) $queryBuilder
            ->delete(self::AUTHORIZATION_TABLE)
            ->where(
                $queryBuilder->expr()->or(
                    // Revoked records
                    $queryBuilder->expr()->eq('revoked', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
                    // Expired authorization codes (not yet exchanged)
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->neq('authorization_code_hash', $queryBuilder->createNamedParameter('')),
                        $queryBuilder->expr()->gt(
                            'code_expires',
                            $queryBuilder->createNamedParameter(0, ParameterType::INTEGER),
                        ),
                        $queryBuilder->expr()->lt(
                            'code_expires',
                            $queryBuilder->createNamedParameter($now, ParameterType::INTEGER),
                        ),
                    ),
                    // Expired refresh tokens (access token already expired too)
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->gt(
                            'refresh_token_expires',
                            $queryBuilder->createNamedParameter(0, ParameterType::INTEGER),
                        ),
                        $queryBuilder->expr()->lt(
                            'refresh_token_expires',
                            $queryBuilder->createNamedParameter($now, ParameterType::INTEGER),
                        ),
                    ),
                ),
            )
            ->executeStatement();
    }
}
