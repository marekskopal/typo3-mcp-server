<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use Mcp\Server;
use MarekSkopal\MsMcpServer\Server\McpServerFactory;
use MarekSkopal\MsMcpServer\Service\DataHandlerService;
use MarekSkopal\MsMcpServer\Service\FileService;
use MarekSkopal\MsMcpServer\Service\RecordService;
use MarekSkopal\MsMcpServer\Service\TcaSchemaService;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[CoversClass(McpServerFactory::class)]
final class McpServerFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/tmp/typo3-test',
            '/tmp/typo3-test/public',
            '/tmp/typo3-test/var',
            '/tmp/typo3-test/config',
            '/tmp/typo3-test/index.php',
            'UNIX',
        );
    }

    public function testCreateReturnsServerInstance(): void
    {
        $connectionPool = $this->createStub(ConnectionPool::class);
        $storageRepository = $this->createStub(StorageRepository::class);
        $recordService = new RecordService($connectionPool);
        $dataHandlerService = new DataHandlerService();
        $fileService = new FileService($storageRepository);
        $logger = new NullLogger();

        $tcaSchemaService = new TcaSchemaService();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($recordService, $dataHandlerService, $fileService, $tcaSchemaService, $logger): object {
                return match (true) {
                    str_contains($id, 'RecordService') => $recordService,
                    str_contains($id, 'DataHandlerService') => $dataHandlerService,
                    str_contains($id, 'FileService') => $fileService,
                    str_contains($id, 'TcaSchemaService') => $tcaSchemaService,
                    str_contains($id, 'LoggerInterface') || str_contains($id, 'Logger') => $logger,
                    default => new ($id)($recordService, $logger),
                };
            },
        );

        $dynamicToolRegistrar = new DynamicToolRegistrar($recordService, $dataHandlerService, new TcaSchemaService(), $logger);

        $factory = new McpServerFactory($container, $dynamicToolRegistrar);
        $server = $factory->create();

        self::assertInstanceOf(Server::class, $server);
    }
}
