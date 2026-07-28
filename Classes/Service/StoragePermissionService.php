<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Applies a backend user's filemounts and file-operation permissions to a ResourceStorage.
 *
 * Core does this in `StoragePermissionsAspect`, an `AfterResourceStorageInitializationEvent`
 * listener — but that listener is gated on `ApplicationType::fromRequest(...)->isBackend()`.
 * The MCP endpoint runs in the *frontend* middleware stack and `mcp:server` runs in CLI, so the
 * listener never fires for us and `StorageRepository` hands out storages with their constructor
 * defaults: `evaluatePermissions = false`, no filemounts, empty `userPermissions`.
 *
 * That is not a cosmetic difference. `isWithinFileMountBoundaries()` and
 * `checkUserActionPermission()` both short-circuit to `true` while `evaluatePermissions` is off,
 * so every folder guard downstream passes and a non-admin reaches the *entire* storage — not just
 * their mounts — plus their `file_permissions` (addFile/deleteFile/…) are ignored. Restoring the
 * same restrictions here is what makes the filemount confinement real on the MCP paths.
 */
readonly class StoragePermissionService
{
    public function applyUserPermissions(ResourceStorage $storage, BackendUserAuthentication $backendUser): void
    {
        // Admins see every storage without filters, and the fallback storage is never restricted.
        // Both mirror core's aspect.
        // @phpstan-ignore method.internal
        if ($backendUser->isAdmin() || $storage->isFallbackStorage()) {
            return;
        }

        // Storage objects are cached per request by StorageRepository, so the same instance can
        // reach us repeatedly. Permission evaluation being on means it was already configured —
        // either by core's aspect or by an earlier call here — and re-applying would be pointless.
        if ($storage->getEvaluatePermissions()) {
            return;
        }

        $storage->setEvaluatePermissions(true);
        $storage->setUserPermissions($this->resolveFilePermissions($storage, $backendUser));
        $this->addFileMounts($storage, $backendUser);
    }

    /**
     * User file permissions, overlaid with any per-storage `permissions.file.storage.<uid>` TSconfig.
     *
     * @return array<string, bool>
     */
    private function resolveFilePermissions(ResourceStorage $storage, BackendUserAuthentication $backendUser): array
    {
        $permissions = [];
        /** @var mixed $value */
        foreach ($backendUser->getFilePermissions() as $permission => $value) {
            $permissions[(string) $permission] = (bool) $value;
        }

        $storagePermissions = $backendUser->getTSConfig();
        foreach (['permissions.', 'file.', 'storage.', $storage->getUid() . '.'] as $key) {
            /** @var mixed $storagePermissions */
            $storagePermissions = is_array($storagePermissions) ? ($storagePermissions[$key] ?? null) : null;
        }

        if (!is_array($storagePermissions)) {
            return $permissions;
        }

        /** @var mixed $value */
        foreach ($storagePermissions as $permission => $value) {
            $permissions[(string) $permission] = (bool) $value;
        }

        return $permissions;
    }

    private function addFileMounts(ResourceStorage $storage, BackendUserAuthentication $backendUser): void
    {
        // @phpstan-ignore method.internal
        foreach ($backendUser->getFileMountRecords() as $record) {
            if (!is_array($record)) {
                continue;
            }

            /** @var array<string, mixed> $fileMountRow */
            $fileMountRow = $record;

            /** @var mixed $identifier */
            $identifier = $fileMountRow['identifier'] ?? null;
            // A filemount identifier is "<storageUid>:<path>". Anything else is a leftover
            // storage selection rather than a real mount; core skips those too.
            if (!is_string($identifier) || !str_contains($identifier, ':')) {
                continue;
            }

            [$base, $path] = GeneralUtility::trimExplode(':', $identifier, false, 2);
            if ((int) $base !== $storage->getUid()) {
                continue;
            }

            try {
                $storage->addFileMount($path, $fileMountRow);
            } catch (FolderDoesNotExistException) {
                // A mount pointing at a folder that no longer exists is skipped, not fatal —
                // same as core. Note this fails *closed*: if every mount is stale the storage
                // ends up with permission evaluation on and no mounts, denying everything.
            }
        }
    }
}
