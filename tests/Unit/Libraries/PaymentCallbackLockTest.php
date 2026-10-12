<?php

namespace Tests\Unit\Libraries;

use LogicException;
use PaymentCallbackLock;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * GHSA-c2qp-26qj-6gf6 / GHSA-m2c5-pmxf-qfh5: PaymentCallbackLock serializes concurrent payment
 * writers with a connection-scoped MySQL advisory lock. The existing feature-level race tests
 * (AdminPaymentAmountRaceTest, GatewayPaymentRaceTest) prove the application-level behavior —
 * that a second request blocks until the first one finishes — but every one of them dispatches
 * each request as its own subprocess, so the lock is also released implicitly the instant that
 * process exits (MySQL frees a session's advisory locks when its connection closes), whether or
 * not release() itself actually runs. A mutation-check of this class found exactly that gap:
 * deleting the RELEASE_LOCK() call, or short-circuiting release() to always/never return early,
 * survived every feature test unscathed.
 *
 * In a real deployment with a persistent/pooled DB connection (persistent connections, or a
 * long-lived worker reusing one), a release() that silently never calls RELEASE_LOCK() would
 * leave that connection holding the lock for its entire lifetime, starving every future payment
 * on that connection — a real, latent robustness bug no subprocess-per-request test can see.
 * These tests call acquire()/release() directly against one real CI database connection and
 * verify the lock's true state from a second, independent connection, closing that gap.
 */
final class PaymentCallbackLockTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'libraries/PaymentCallbackLock.php';
    }

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_actually_releases_the_mysql_lock_so_a_second_connection_can_acquire_it_immediately(): void
    {
        /* Arrange */
        $ci         = $this->bootCodeIgniter();
        $invoiceId  = random_int(100000, 999999);
        $lock       = new PaymentCallbackLock($ci->db);
        $lockHolder = $this->openSecondaryConnection();

        /* Act */
        self::assertTrue($lock->acquire($invoiceId, 5), 'Setup: the lock must be acquirable before release() is even tested.');
        $lock->release();

        /* Assert: a second, independent connection must be able to take the exact same named
         * lock without waiting at all (timeout 0) -- if release() were a no-op (the surviving
         * mutant), GET_LOCK would still be held by $ci->db's own connection and this would
         * come back 0, not 1. */
        $probe = $lockHolder->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        $probe->execute(['ip:payment:invoice:' . $invoiceId]);
        self::assertSame(1, (int) $probe->fetch(PDO::FETCH_ASSOC)['acquired'], 'The lock was still held after release() returned -- release() did not actually run RELEASE_LOCK().');

        $release = $lockHolder->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['ip:payment:invoice:' . $invoiceId]);
    }

    #[Test]
    public function it_is_a_safe_no_op_to_release_a_lock_that_was_never_acquired(): void
    {
        /* Arrange: a fresh instance that never called acquire() */
        $ci  = $this->bootCodeIgniter();
        $lock = new PaymentCallbackLock($ci->db);

        /* Act */
        $lock->release();

        /* Assert: no exception, and a second call is equally safe (idempotent) */
        $lock->release();
        self::assertTrue(true);
    }

    #[Test]
    public function it_refuses_to_acquire_the_same_lock_instance_twice_in_a_row(): void
    {
        /* Arrange */
        $ci        = $this->bootCodeIgniter();
        $invoiceId = random_int(100000, 999999);
        $lock      = new PaymentCallbackLock($ci->db);
        self::assertTrue($lock->acquire($invoiceId, 5));

        /* Act + Assert: acquiring again on the same instance before releasing is a programmer
         * error (LogicException), not a silent no-op -- this one instance tracks exactly one
         * held lock name. */
        $this->expectException(LogicException::class);
        try {
            $lock->acquire($invoiceId, 5);
        } finally {
            $lock->release();
        }
    }

    private function openSecondaryConnection(): PDO
    {
        $basePath = dirname(__DIR__, 3);

        $active_group = null;
        $db           = [];
        require $basePath . '/application/config/database.php';

        $cfg = $db[$active_group ?? 'default'] ?? [];

        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8',
                (string) ($cfg['hostname'] ?? '127.0.0.1'),
                (int) ($cfg['port'] ?? 3306),
                (string) ($cfg['database'] ?? '')
            ),
            (string) ($cfg['username'] ?? ''),
            (string) ($cfg['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
