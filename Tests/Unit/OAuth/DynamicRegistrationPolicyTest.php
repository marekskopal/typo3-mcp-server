<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use MarekSkopal\MsMcpServer\OAuth\DynamicRegistrationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(DynamicRegistrationPolicy::class)]
final class DynamicRegistrationPolicyTest extends TestCase
{
    /** @return iterable<string, array{0: mixed, 1: bool}> */
    public static function configurationProvider(): iterable
    {
        yield 'setting absent keeps registration open (backwards compatible)' => [[], true];
        yield 'explicitly enabled' => [['dynamicClientRegistrationEnabled' => '1'], true];
        yield 'disabled' => [['dynamicClientRegistrationEnabled' => '0'], false];
        yield 'disabled as boolean' => [['dynamicClientRegistrationEnabled' => false], false];
        yield 'no extension configuration at all' => [null, true];
    }

    #[DataProvider('configurationProvider')]
    public function testIsEnabledFollowsTheExtensionSetting(mixed $config, bool $expected): void
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($config);

        self::assertSame($expected, (new DynamicRegistrationPolicy($extensionConfiguration))->isEnabled());
    }
}
