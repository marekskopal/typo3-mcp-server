<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Server\Session;

use Mcp\Exception\SessionLockException;
use Mcp\Exception\SessionLockTimeoutException;
use Mcp\Server\Session\SessionLockInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Serializes the concurrent requests of one MCP session through TYPO3's own `LockFactory`.
 *
 * The SDK ships `FileSessionLock` and `SymfonyLockSessionLock`, but symfony/lock is not part of a TYPO3
 * install, and TYPO3 already has a configurable locking API (file / flock / semaphore strategies,
 * replaceable by extensions). Its strategies are local to one machine, like core's own page-generation
 * lock; a multi-node setup still gets the SDK's per-key session merge, just not full serialization.
 *
 * A fatal error in a handler skips the protocol's release, so the first acquire registers
 * {@see releaseAll()} as a shutdown function, mirroring `SymfonyLockSessionLock`.
 */
class Typo3SessionLock implements SessionLockInterface
{
    private const string PREFIX = 'mcp-session-';

    private const int RETRY_DELAY_MICROSECONDS = 10000;

    private const int CAPABILITIES = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE
        | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

    /** @var array<string, LockingStrategyInterface> */
    private array $locks = [];

    private bool $releasesOnShutdown = false;

    /** @param float $timeout how long to wait for a lock held by another request, in seconds */
    public function __construct(private readonly LockFactory $lockFactory, private readonly float $timeout = 30.0)
    {
    }

    public function acquire(Uuid $sessionId): void
    {
        if (!$this->lock($sessionId, $this->timeout)) {
            throw new SessionLockTimeoutException(sprintf(
                'Timed out after %.3fs waiting for the lock of session "%s".',
                $this->timeout,
                $sessionId->toRfc4122(),
            ));
        }
    }

    public function tryAcquire(Uuid $sessionId): bool
    {
        return $this->lock($sessionId, 0.0);
    }

    public function release(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        $lock = $this->locks[$key] ?? null;
        if ($lock === null) {
            throw new SessionLockException(sprintf('The lock of session "%s" is not held.', $key));
        }

        unset($this->locks[$key]);

        try {
            $lock->release();
        } catch (Throwable $e) {
            throw new SessionLockException(sprintf('Failed to release the lock of session "%s".', $key), 0, $e);
        }
    }

    /** Releases every lock this instance still holds; failures are swallowed, the lock dies with the process. */
    public function releaseAll(): void
    {
        foreach ($this->locks as $key => $lock) {
            unset($this->locks[$key]);

            try {
                $lock->release();
            } catch (Throwable) {
                // Nothing left to report to at shutdown.
            }
        }
    }

    /** @return bool false when another request still held the lock after $timeout seconds */
    private function lock(Uuid $sessionId, float $timeout): bool
    {
        $key = $sessionId->toRfc4122();

        if (isset($this->locks[$key])) {
            throw new SessionLockException(sprintf('The lock of session "%s" is already held.', $key));
        }

        $deadline = hrtime(true) + (int) ($timeout * 1000000000);

        try {
            $lock = $this->lockFactory->createLocker(self::PREFIX . $key, self::CAPABILITIES);

            while (!$this->tryLock($lock)) {
                if (hrtime(true) >= $deadline) {
                    return false;
                }

                usleep(self::RETRY_DELAY_MICROSECONDS);
            }
        } catch (Throwable $e) {
            throw new SessionLockException(sprintf('Failed to acquire the lock of session "%s".', $key), 0, $e);
        }

        $this->locks[$key] = $lock;

        if (!$this->releasesOnShutdown) {
            register_shutdown_function($this->releaseAll(...));
            $this->releasesOnShutdown = true;
        }

        return true;
    }

    private function tryLock(LockingStrategyInterface $lock): bool
    {
        try {
            return (bool) $lock->acquire(self::CAPABILITIES);
        } catch (LockAcquireWouldBlockException) {
            return false;
        }
    }
}
