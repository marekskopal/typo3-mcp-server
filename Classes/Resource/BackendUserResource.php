<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Resource;

use MarekSkopal\MsMcpServer\Resource\Result\BackendUserResult;
use Mcp\Capability\Attribute\McpResource;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use const JSON_THROW_ON_ERROR;

readonly class BackendUserResource
{
    #[McpResource(
        uri: 'typo3://user/me',
        name: 'backend_user',
        description: 'Current authenticated backend user information including UID, username, admin status, and group membership.',
        mimeType: 'application/json',
    )]
    public function execute(): string
    {
        $user = $this->getAuthenticatedUserData();

        $uid = $user['uid'] ?? 0;
        $admin = $user['admin'] ?? 0;

        $result = new BackendUserResult(
            uid: is_int($uid) ? $uid : (is_string($uid) ? (int) $uid : 0),
            username: $this->getString($user, 'username'),
            email: $this->getString($user, 'email'),
            isAdmin: (is_int($admin) ? $admin : (is_string($admin) ? (int) $admin : 0)) === 1,
            lang: $this->getString($user, 'lang'),
            usergroups: $this->getString($user, 'usergroup'),
        );

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /** @param array<mixed> $data */
    private function getString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
    }

    /** @return array<mixed> */
    private function getAuthenticatedUserData(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No authenticated backend user available', 1714000001);
        }

        // @phpstan-ignore property.internal
        $user = $backendUser->user;

        if (!is_array($user)) {
            throw new \RuntimeException('No authenticated backend user available', 1714000001);
        }

        return $user;
    }
}
