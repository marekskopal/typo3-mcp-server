<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Resource\Result\TcaTableEntry;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ResourceReadException;
use Psr\Log\LoggerInterface;
use const JSON_THROW_ON_ERROR;

readonly class TcaTablesResource
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[McpResource(
        uri: 'typo3://schema/tables',
        name: 'tca_tables',
        description: 'List of all available TCA database tables with their labels.',
        mimeType: 'application/json',
    )]
    public function execute(): string
    {
        try {
            /** @var array<string, array{ctrl?: array{title?: string}}> $tca */
            $tca = $GLOBALS['TCA'] ?? [];
            $tables = [];

            foreach ($tca as $tableName => $config) {
                $tables[] = new TcaTableEntry(table: $tableName, label: $config['ctrl']['title'] ?? $tableName);
            }

            return json_encode($tables, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('tca_tables resource failed', ['exception' => $e]);

            throw new ResourceReadException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
