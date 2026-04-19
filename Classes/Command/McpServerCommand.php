<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Command;

use Doctrine\DBAL\ParameterType;
use MarekSkopal\MsMcpServer\Authentication\BackendUserBootstrap;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use const STDIN;
use const STDOUT;

#[AsCommand(name: 'mcp:server', description: 'Start the MCP server using stdio transport for local AI tool integration')]
class McpServerCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly BackendUserBootstrap $backendUserBootstrap,
        private readonly McpServerFactory $mcpServerFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Backend username to authenticate as', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $username */
        $username = $input->getOption('user');

        $beUserUid = $this->resolveBackendUserUid($username);
        if ($beUserUid === null) {
            $output->writeln(sprintf('<error>Backend user "%s" not found or disabled.</error>', $username));
            return Command::FAILURE;
        }

        $this->backendUserBootstrap->bootstrap($beUserUid);

        $server = $this->mcpServerFactory->create();
        $transport = new StdioTransport(STDIN, STDOUT, $this->logger);

        return $server->run($transport);
    }

    private function resolveBackendUserUid(string $username): ?int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array{uid: int|string}|false $row */
        $row = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int) $row['uid'];
    }
}
