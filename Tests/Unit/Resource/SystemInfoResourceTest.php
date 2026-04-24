<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\SystemInfoResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use const JSON_THROW_ON_ERROR;

#[CoversClass(SystemInfoResource::class)]
final class SystemInfoResourceTest extends TestCase
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

    public function testExecuteReturnsSystemInfo(): void
    {
        $typo3Version = $this->createStub(Typo3Version::class);
        $typo3Version->method('getVersion')->willReturn('13.4.0');

        $resource = new SystemInfoResource($typo3Version);
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('13.4.0', $result['typo3Version']);
        self::assertSame(PHP_VERSION, $result['phpVersion']);
        self::assertSame('Testing', $result['applicationContext']);
        self::assertSame(PHP_OS_FAMILY, $result['os']);
        self::assertSame('/tmp/typo3-test', $result['projectPath']);
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $typo3Version = $this->createStub(Typo3Version::class);
        $typo3Version->method('getVersion')->willThrowException(new \RuntimeException('Version unavailable'));

        $resource = new SystemInfoResource($typo3Version);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Version unavailable');

        $resource->execute();
    }
}
