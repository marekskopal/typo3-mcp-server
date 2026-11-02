<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Repository\McpSessionRepository;
use MarekSkopal\MsMcpServer\Server\Session\DatabaseSessionStore;
use MarekSkopal\MsMcpServer\Server\Session\Typo3SessionLock;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Redirect\RedirectToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Scheduler\SchedulerToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\TypoScript\TypoScriptToolRegistrar;
use MarekSkopal\MsMcpServer\Tool\Workspace\WorkspaceToolRegistrar;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server;
use Mcp\Server\Builder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Locking\LockFactory;

readonly class McpServerFactory
{
    public const string VERSION = '1.4.1';

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
        private TypoScriptToolRegistrar $typoScriptToolRegistrar,
        private WorkspaceToolRegistrar $workspaceToolRegistrar,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
        private McpSessionRepository $sessionRepository,
        private LockFactory $lockFactory,
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

    public function create(int $beUserUid): Server
    {
        $sessionStore = new DatabaseSessionStore($this->sessionRepository, $this->sessionLifetime, $beUserUid);

        $handlerTypes = $this->buildHandlerTypeMap();
        $errorHandlingContainer = new ErrorHandlingContainer($this->container, $this->logger, $this->auditLogger, $handlerTypes);

        $builder = $this->newBuilder()
            ->setServerInfo('TYPO3 MCP Server', self::VERSION)
            ->setContainer($errorHandlingContainer)
            ->setSession($sessionStore)
            // Serialize concurrent requests of one session (parallel tool calls), so none of them
            // overwrites what another saved. A new instance per server: the lock tracks what it holds.
            ->setSessionLock(new Typo3SessionLock($this->lockFactory))
            ->setPaginationLimit(500);

        foreach ($this->tools as $tool) {
            $attribute = $this->getMethodAttribute($tool, McpTool::class);
            // Hand over the whole attribute: given only a name, the SDK falls back to the method's
            // docblock for the description, and `execute` has none — the client got a bare schema (#36).
            $builder->addTool(
                handler: [$tool::class, 'execute'],
                name: $attribute?->name,
                title: $attribute?->title,
                description: $attribute?->description,
                annotations: $attribute?->annotations,
                icons: $attribute?->icons,
                meta: $attribute?->meta,
                outputSchema: $attribute?->outputSchema,
            );
        }

        $this->dynamicToolRegistrar->register($builder);
        $this->redirectToolRegistrar->register($builder);
        $this->schedulerToolRegistrar->register($builder);
        $this->typoScriptToolRegistrar->register($builder);
        $this->workspaceToolRegistrar->register($builder);

        foreach ($this->resources as $resource) {
            $attribute = $this->getMethodAttribute($resource, McpResource::class);
            if ($attribute !== null) {
                $builder->addResource(
                    handler: [$resource::class, 'execute'],
                    uri: $attribute->uri,
                    name: $attribute->name,
                    title: $attribute->title,
                    description: $attribute->description,
                    mimeType: $attribute->mimeType,
                    size: $attribute->size,
                    annotations: $attribute->annotations,
                    icons: $attribute->icons,
                    meta: $attribute->meta,
                );
                continue;
            }

            $templateAttribute = $this->getMethodAttribute($resource, McpResourceTemplate::class);
            if ($templateAttribute === null) {
                continue;
            }

            $builder->addResourceTemplate(
                handler: [$resource::class, 'execute'],
                uriTemplate: $templateAttribute->uriTemplate,
                name: $templateAttribute->name,
                title: $templateAttribute->title,
                description: $templateAttribute->description,
                mimeType: $templateAttribute->mimeType,
                annotations: $templateAttribute->annotations,
                meta: $templateAttribute->meta,
            );
        }

        foreach ($this->prompts as $prompt) {
            $attribute = $this->getMethodAttribute($prompt, McpPrompt::class);
            $builder->addPrompt(
                handler: [$prompt::class, 'execute'],
                name: $attribute?->name,
                title: $attribute?->title,
                description: $attribute?->description,
                icons: $attribute?->icons,
                meta: $attribute?->meta,
            );
        }

        return $builder->build();
    }

    /** Seam for tests, which hand the builder a registry they can read back. */
    protected function newBuilder(): Builder
    {
        return Server::builder();
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

    /** @return array<class-string, 'tool'|'resource'|'prompt'> */
    private function buildHandlerTypeMap(): array
    {
        $map = [];

        foreach ($this->tools as $tool) {
            $map[$tool::class] = 'tool';
        }

        foreach ($this->resources as $resource) {
            $map[$resource::class] = 'resource';
        }

        foreach ($this->prompts as $prompt) {
            $map[$prompt::class] = 'prompt';
        }

        return $map;
    }
}
