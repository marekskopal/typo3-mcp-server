<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server;

use MarekSkopal\MsMcpServer\Tool\Dynamic\DynamicToolRegistrar;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\Environment;

readonly class McpServerFactory
{
    public const string VERSION = '0.4.0';

    /**
     * @param iterable<object> $tools
     * @param iterable<object> $resources
     * @param iterable<object> $prompts
     */
    public function __construct(
        private ContainerInterface $container,
        private DynamicToolRegistrar $dynamicToolRegistrar,
        private iterable $tools,
        private iterable $resources,
        private iterable $prompts,
    ) {
    }

    public function create(): Server
    {
        $sessionDir = Environment::getVarPath() . '/mcp-sessions';
        $sessionStore = new FileSessionStore($sessionDir);

        $builder = Server::builder()
            ->setServerInfo('TYPO3 MCP Server', self::VERSION)
            ->setContainer($this->container)
            ->setSession($sessionStore, new InitializedSessionFactory());

        foreach ($this->tools as $tool) {
            $builder->addTool([$tool::class, 'execute']);
        }

        $this->dynamicToolRegistrar->register($builder);

        foreach ($this->resources as $resource) {
            $attribute = $this->getMethodAttribute($resource, McpResource::class);
            if ($attribute !== null) {
                $builder->addResource([$resource::class, 'execute'], $attribute->uri);
                continue;
            }

            $templateAttribute = $this->getMethodAttribute($resource, McpResourceTemplate::class);
            if ($templateAttribute !== null) {
                $builder->addResourceTemplate([$resource::class, 'execute'], $templateAttribute->uriTemplate);
            }
        }

        foreach ($this->prompts as $prompt) {
            $builder->addPrompt([$prompt::class, 'execute']);
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
}
