<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\SystemInfoResource;
use MarekSkopal\MsMcpServer\Service\PermissionService;
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

    public function testExecuteReturnsFullSystemInfoForAdmin(): void
    {
        $result = $this->executeAs(isAdmin: true);

        self::assertSame('13.4.0', $result['typo3Version']);
        self::assertSame(PHP_VERSION, $result['phpVersion']);
        self::assertSame('Testing', $result['applicationContext']);
        self::assertSame(PHP_OS_FAMILY, $result['os']);
        self::assertSame('/tmp/typo3-test', $result['projectPath']);
    }

    /**
     * An editor never sees the absolute install path or the application context in the backend —
     * core gates its System Information toolbar, which shows exactly these, on isAdmin().
     */
    public function testExecuteWithholdsDeploymentDetailFromNonAdmin(): void
    {
        $result = $this->executeAs(isAdmin: false);

        self::assertNull($result['projectPath']);
        self::assertNull($result['applicationContext']);
        self::assertStringNotContainsString('/tmp/typo3-test', json_encode($result, JSON_THROW_ON_ERROR));

        // The TYPO3 version stays: it is in the About module for every backend user, and a client
        // needs it to know which API shapes apply.
        self::assertSame('13.4.0', $result['typo3Version']);
        self::assertSame(PHP_OS_FAMILY, $result['os']);
    }

    /** @return array<string, mixed> */
    private function executeAs(bool $isAdmin): array
    {
        $typo3Version = $this->createStub(Typo3Version::class);
        $typo3Version->method('getVersion')->willReturn('13.4.0');

        $permissionService = $this->createStub(PermissionService::class);
        $permissionService->method('isAdmin')->willReturn($isAdmin);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (new SystemInfoResource($typo3Version, $permissionService))->execute(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    public function testExecuteThrowsExceptionOnError(): void
    {
        $typo3Version = $this->createStub(Typo3Version::class);
        $typo3Version->method('getVersion')->willThrowException(new \RuntimeException('Version unavailable'));

        $resource = new SystemInfoResource($typo3Version, $this->createStub(PermissionService::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Version unavailable');

        $resource->execute();
    }
}
