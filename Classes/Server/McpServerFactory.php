<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Repository\McpSessionRepository;
use MarekSkopal\MsMcpServer\Server\Session\DatabaseSessionStore;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Redirect\RedirectToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Scheduler\SchedulerToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Workspace\WorkspaceToolRegistrar;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

readonly class McpServerFactory
{
    public const string VERSION = '0.10.0';

    private const int DEFAULT_SESSION_LIFETIME = 86400;

    private int $sessionLifetime;

    /**
     * @param iterable<object> $tools
     * @param iterable<object> $resources
     * @param iterable<object> $prompts
     */
    public function __construct(
        private ContainerInterface $container,
        private DynamicToolRegistrar $dynamicToolRegistrar,
        private RedirectToolRegistrar $redirectToolRegistrar,
        private SchedulerToolRegistrar $schedulerToolRegistrar,
        private WorkspaceToolRegistrar $workspaceToolRegistrar,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
        private McpSessionRepository $sessionRepository,
        ExtensionConfiguration $extensionConfiguration,
        private iterable $tools,
        private iterable $resources,
        private iterable $prompts,
    ) {
        $config = $extensionConfiguration->get('ms_mcp_server');
        $sessionLifetime = is_array($config) ? ($config['sessionLifetime'] ?? null) : null;
        $resolved = is_numeric($sessionLifetime) ? (int) $sessionLifetime : self::DEFAULT_SESSION_LIFETIME;
        $this->sessionLifetime = $resolved > 0 ? $resolved : self::DEFAULT_SESSION_LIFETIME;
    }

    public function create(): Server
    {
        $sessionStore = new DatabaseSessionStore($this->sessionRepository, $this->sessionLifetime);

        $handlerTypes = $this->buildHandlerTypeMap();
        $errorHandlingContainer = new ErrorHandlingContainer($this->container, $this->logger, $this->auditLogger, $handlerTypes);

        $builder = Server::builder()
            ->setServerInfo('TYPO3 MCP Server', self::VERSION)
            ->setContainer($errorHandlingContainer)
            ->setSession($sessionStore)
            ->setPaginationLimit(500);

        foreach ($this->tools as $tool) {
            $attribute = $this->getMethodAttribute($tool, McpTool::class);
            $builder->addTool([$tool::class, 'execute'], $attribute?->name);
        }

        $this->dynamicToolRegistrar->register($builder);
        $this->redirectToolRegistrar->register($builder);
        $this->schedulerToolRegistrar->register($builder);
        $this->workspaceToolRegistrar->register($builder);

        foreach ($this->resources as $resource) {
            $attribute = $this->getMethodAttribute($resource, McpResource::class);
            if ($attribute !== null) {
                $builder->addResource([$resource::class, 'execute'], $attribute->uri, $attribute->name);
                continue;
            }

            $templateAttribute = $this->getMethodAttribute($resource, McpResourceTemplate::class);
            if ($templateAttribute !== null) {
                $builder->addResourceTemplate([$resource::class, 'execute'], $templateAttribute->uriTemplate, $templateAttribute->name);
            }
        }

        foreach ($this->prompts as $prompt) {
            $attribute = $this->getMethodAttribute($prompt, McpPrompt::class);
            $builder->addPrompt([$prompt::class, 'execute'], $attribute?->name);
        }

        return $builder->build();
    }

    /**
     * @param class-string<T> $attributeClass
     * @return T|null
     * @template T of object
     */
    private function getMethodAttribute(object $instance, string $attributeClass): ?object
    {
        $reflection = new ReflectionMethod($instance, 'execute');
        $attributes = $reflection->getAttributes($attributeClass);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /** @return array<class-string, 'tool'|'resource'> */
    private function buildHandlerTypeMap(): array
    {
        $map = [];

        foreach ($this->tools as $tool) {
            $map[$tool::class] = 'tool';
        }

        foreach ($this->resources as $resource) {
            $map[$resource::class] = 'resource';
        }

        return $map;
    }
}
