<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Command;

use DirectoryIterator;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(name: 'mcp:cleanup', description: 'Remove expired and revoked OAuth authorizations and stale MCP session files')]
final class CleanupExpiredTokensCommand extends Command
{
    private const string AUTHORIZATION_TABLE = 'tx_msmcpserver_oauth_authorization';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deletedAuthorizations = $this->deleteExpiredAuthorizations();
        $io->writeln(sprintf('Deleted %d expired/revoked OAuth authorizations.', $deletedAuthorizations));

        $deletedSessions = $this->deleteExpiredSessionFiles();
        $io->writeln(sprintf('Deleted %d stale MCP session files.', $deletedSessions));

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

    private function deleteExpiredSessionFiles(): int
    {
        $sessionDir = Environment::getVarPath() . '/mcp-sessions';
        if (!is_dir($sessionDir)) {
            return 0;
        }

        $deleted = 0;
        // 24 hours
        $maxAge = 86400;

        $iterator = new DirectoryIterator($sessionDir);
        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }

            if ($file->getMTime() >= time() - $maxAge) {
                continue;
            }

            unlink($file->getPathname());
            $deleted++;
        }

        return $deleted;
    }
}
