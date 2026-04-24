<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Resource;

use MarekSkopal\MsMcpServer\Resource\BackendUserResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use const JSON_THROW_ON_ERROR;

#[CoversClass(BackendUserResource::class)]
final class BackendUserResourceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    public function testExecuteReturnsUserInfo(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->user = [
            'uid' => 1,
            'username' => 'admin',
            'email' => 'admin@example.com',
            'admin' => 1,
            'lang' => 'en',
            'usergroup' => '1,2',
        ];

        $GLOBALS['BE_USER'] = $backendUser;

        $resource = new BackendUserResource();
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $result['uid']);
        self::assertSame('admin', $result['username']);
        self::assertSame('admin@example.com', $result['email']);
        self::assertTrue($result['isAdmin']);
        self::assertSame('en', $result['lang']);
        self::assertSame('1,2', $result['usergroups']);
    }

    public function testExecuteReturnsNonAdminUser(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->user = [
            'uid' => 5,
            'username' => 'editor',
            'email' => 'editor@example.com',
            'admin' => 0,
            'lang' => 'de',
            'usergroup' => '3',
        ];

        $GLOBALS['BE_USER'] = $backendUser;

        $resource = new BackendUserResource();
        $result = json_decode($resource->execute(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(5, $result['uid']);
        self::assertSame('editor', $result['username']);
        self::assertFalse($result['isAdmin']);
        self::assertSame('de', $result['lang']);
    }

    public function testExecuteThrowsExceptionWhenNoUser(): void
    {
        unset($GLOBALS['BE_USER']);

        $resource = new BackendUserResource();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated backend user available');

        $resource->execute();
    }

    public function testExecuteThrowsExceptionWhenUserIsNull(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->user = null;

        $GLOBALS['BE_USER'] = $backendUser;

        $resource = new BackendUserResource();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated backend user available');

        $resource->execute();
    }
}
