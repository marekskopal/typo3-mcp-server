<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Server\Session;

use MarekSkopal\MsMcpServer\Server\Session\Typo3SessionLock;
use Mcp\Exception\SessionLockException;
use Mcp\Exception\SessionLockTimeoutException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireException;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

#[CoversClass(Typo3SessionLock::class)]
final class Typo3SessionLockTest extends TestCase
{
    public function testAcquireCreatesExclusiveNonBlockingLockerKeyedBySession(): void
    {
        $uuid = Uuid::v4();
        $capabilities = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->expects(self::once())->method('acquire')->with($capabilities)->willReturn(true);
        $locker->expects(self::once())->method('release')->willReturn(true);

        $factory = $this->createMock(LockFactory::class);
        $factory->expects(self::once())
            ->method('createLocker')
            ->with('mcp-session-' . $uuid->toRfc4122(), $capabilities)
            ->willReturn($locker);

        $lock = new Typo3SessionLock($factory);
        $lock->acquire($uuid);
        $lock->release($uuid);
    }

    public function testAcquireRetriesWhileTheLockWouldBlock(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->expects(self::exactly(3))
            ->method('acquire')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new LockAcquireWouldBlockException('busy', 1)),
                self::throwException(new LockAcquireWouldBlockException('busy', 1)),
                true,
            );

        $lock = new Typo3SessionLock($this->factoryReturning($locker), 5.0);
        $lock->acquire(Uuid::v4());
    }

    public function testAcquireTimesOut(): void
    {
        $locker = $this->createStub(LockingStrategyInterface::class);
        $locker->method('acquire')->willThrowException(new LockAcquireWouldBlockException('busy', 1));

        $lock = new Typo3SessionLock($this->factoryReturning($locker), 0.0);

        $this->expectException(SessionLockTimeoutException::class);
        $lock->acquire(Uuid::v4());
    }

    public function testTryAcquireReturnsFalseWhenHeldElsewhere(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->expects(self::once())->method('acquire')->willThrowException(new LockAcquireWouldBlockException('busy', 1));

        $lock = new Typo3SessionLock($this->factoryReturning($locker));

        self::assertFalse($lock->tryAcquire(Uuid::v4()));
    }

    public function testBackendFailureBecomesSessionLockException(): void
    {
        $locker = $this->createStub(LockingStrategyInterface::class);
        $locker->method('acquire')->willThrowException(new LockAcquireException('cannot open', 1));

        $lock = new Typo3SessionLock($this->factoryReturning($locker));

        $this->expectException(SessionLockException::class);
        $lock->acquire(Uuid::v4());
    }

    public function testAcquiringAHeldLockTwiceIsRefused(): void
    {
        $uuid = Uuid::v4();
        $locker = $this->createStub(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);

        $lock = new Typo3SessionLock($this->factoryReturning($locker));
        $lock->acquire($uuid);

        $this->expectException(SessionLockException::class);
        $lock->acquire($uuid);
    }

    public function testReleasingAnUnheldLockIsRefused(): void
    {
        $lock = new Typo3SessionLock($this->createStub(LockFactory::class));

        $this->expectException(SessionLockException::class);
        $lock->release(Uuid::v4());
    }

    public function testReleaseAllReleasesEveryHeldLock(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);
        $locker->expects(self::exactly(2))->method('release')->willReturn(true);

        $lock = new Typo3SessionLock($this->factoryReturning($locker));
        $lock->acquire(Uuid::v4());
        $lock->acquire(Uuid::v4());
        $lock->releaseAll();
        $lock->releaseAll();
    }

    private function factoryReturning(LockingStrategyInterface $locker): LockFactory
    {
        $factory = $this->createStub(LockFactory::class);
        $factory->method('createLocker')->willReturn($locker);

        return $factory;
    }
}
