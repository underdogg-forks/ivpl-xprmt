<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-cjwm-qx8w-hrq2: the per-account login lockout never expired. authenticate() stops
 * counting at 10 failures, so the unlock rule (log_count >= 10 AND older than 12 hours) had
 * to compare with >= 10; with > 10 the counter could never exceed 10, the unlock branch was
 * unreachable, and the account stayed locked forever. LoginSecurityTest covers the locked
 * (10, fresh) and almost-locked (9) cases but never an old lockout.
 */
final class LoginLockoutExpiryTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsGuest();
    }

    #[Test]
    public function it_unlocks_an_account_whose_lockout_is_older_than_twelve_hours(): void
    {
        /* Arrange: exactly 10 failures, the newest 13 hours ago */
        $email = 'expired-lockout@test.local';
        $this->seedUser($email);
        $this->seedLog($this->accountKey($email), 10, '-13 hours');

        /* Act */
        $response = $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'correct-password']);

        /* Assert: the correct password logs in, and the stale counter is gone */
        $this->assertResponseRedirectsToRoute($response, 'dashboard');
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->accountKey($email)]);
    }

    #[Test]
    public function it_keeps_an_account_locked_while_the_lockout_is_younger_than_twelve_hours(): void
    {
        /* Arrange: exactly 10 failures, the newest 11 hours ago */
        $email = 'recent-lockout@test.local';
        $this->seedUser($email);
        $this->seedLog($this->accountKey($email), 10, '-11 hours');

        /* Act */
        $response = $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'correct-password']);

        /* Assert: still locked out, counter untouched */
        self::assertTrue($response->isRedirect());
        self::assertFalse(str_contains($response->redirectUrl(), 'dashboard'), 'A lockout younger than 12 hours must still block a correct password.');
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey($email), 'log_count' => 10]);
    }

    #[Test]
    public function it_does_not_unlock_an_account_below_the_threshold_just_because_the_log_is_old(): void
    {
        /* Arrange: 9 failures from 13 hours ago are not a lockout, so nothing is reset by the unlock rule;
         * the correct password logs in through the normal success path, which clears the counter itself. */
        $email = 'old-nine@test.local';
        $this->seedUser($email);
        $this->seedLog($this->accountKey($email), 9, '-13 hours');

        /* Act */
        $response = $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'correct-password']);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'dashboard');
    }

    private function accountKey(string $email): string
    {
        return 'login_account:' . hash('sha256', mb_strtolower($email));
    }

    private function seedLog(string $key, int $count, string $when): void
    {
        $this->databaseInsert('ip_login_log', [
            'login_name' => $key, 'log_count' => $count, 'log_create_timestamp' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    private function seedUser(string $email): void
    {
        $this->databaseInsert('ip_users', [
            'user_name'         => 'Lockout Tester', 'user_password' => password_hash('correct-password', PASSWORD_BCRYPT),
            'user_psalt'        => bin2hex(random_bytes(10)), 'user_email' => $email, 'user_type' => 1, 'user_active' => 1,
            'user_date_created' => date('Y-m-d H:i:s'), 'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
    }
}
