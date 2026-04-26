<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\Environment;

readonly class McpServerFactory
{
    public const string VERSION = '0.7.0';

    /**
     * @param iterable<object> $tools
     * @param iterable<object> $resources
     * @param iterable<object> $prompts
     */
    public function __construct(
        private ContainerInterface $container,
        private DynamicToolRegistrar $dynamicToolRegistrar,
        private LoggerInterface $logger,
        private AuditLogger $auditLogger,
        private iterable $tools,
        private iterable $resources,
        private iterable $prompts,
    ) {
    }

    public function create(): Server
    {
        $sessionDir = Environment::getVarPath() . '/mcp-sessions';
        $sessionStore = new FileSessionStore($sessionDir);

        $handlerTypes = $this->buildHandlerTypeMap();
        $errorHandlingContainer = new ErrorHandlingContainer($this->container, $this->logger, $this->auditLogger, $handlerTypes);

        $builder = Server::builder()
            ->setServerInfo('TYPO3 MCP Server', self::VERSION)
            ->setContainer($errorHandlingContainer)
            ->setSession($sessionStore);

        foreach ($this->tools as $tool) {
            $attribute = $this->getMethodAttribute($tool, McpTool::class);
            $builder->addTool([$tool::class, 'execute'], $attribute?->name);
        }

        $this->dynamicToolRegistrar->register($builder);

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
