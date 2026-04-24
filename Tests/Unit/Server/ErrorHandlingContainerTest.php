<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server;

use MarekSkopal\MsMcpServer\Logging\AuditLogger;
use MarekSkopal\MsMcpServer\Server\ErrorHandlingContainer;
use MarekSkopal\MsMcpServer\Server\ErrorHandlingProxy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[CoversClass(ErrorHandlingContainer::class)]
final class ErrorHandlingContainerTest extends TestCase
{
    public function testGetWrapsToolInErrorHandlingProxy(): void
    {
        $tool = new \stdClass();

        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('get')->willReturn($tool);

        $container = new ErrorHandlingContainer(
            $inner,
            new NullLogger(),
            $this->createStub(AuditLogger::class),
            [\stdClass::class => 'tool'],
        );

        $result = $container->get(\stdClass::class);

        self::assertInstanceOf(ErrorHandlingProxy::class, $result);
    }

    public function testGetReturnsUnwrappedInstanceForUnknownClass(): void
    {
        $service = new \stdClass();

        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('get')->willReturn($service);

        $container = new ErrorHandlingContainer(
            $inner,
            new NullLogger(),
            $this->createStub(AuditLogger::class),
            [],
        );

        $result = $container->get(\stdClass::class);

        self::assertSame($service, $result);
    }

    public function testHasDelegatesToInnerContainer(): void
    {
        $inner = $this->createStub(ContainerInterface::class);
        $inner->method('has')->willReturn(true);

        $container = new ErrorHandlingContainer(
            $inner,
            new NullLogger(),
            $this->createStub(AuditLogger::class),
            [],
        );

        self::assertTrue($container->has('SomeClass'));
    }
}
