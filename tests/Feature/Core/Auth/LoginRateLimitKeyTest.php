<?php

namespace Tests\Feature\Core\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-r59m-wgg6-h4pv (PR #1748): five different counters share ip_login_log.login_name. The four
 * throttles use namespaced "<prefix>:<sha256>" keys, but the per-account login lockout wrote the raw,
 * unvalidated email — so submitting another counter's key as the "email" and failing to log in
 * incremented that counter (locking out a chosen IP, blocking password recovery, tripping the cron
 * throttle). These tests drive the real login and password-reset endpoints and inspect the table.
 */
#[Group('security')]
class LoginRateLimitKeyTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsGuest();
    }

    #[Test]
    public function a_failed_login_is_counted_under_a_namespaced_account_key_and_never_under_the_raw_email(): void
    {
        /* Arrange */
        $email = 'counted-' . bin2hex(random_bytes(3)) . '@test.local';

        /* Act */
        $this->failLogin($email);

        /* Assert */
        $key = $this->accountKey($email);
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $key, 'log_count' => 1]);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $email]);
    }

    #[Test]
    public function the_account_counter_ignores_the_case_of_the_email(): void
    {
        /* Act */
        $this->failLogin('Mixed.Case@Test.Local');
        $this->failLogin('mixed.case@test.local');
        $this->failLogin('MIXED.CASE@TEST.LOCAL');

        /* Assert: one counter, three failures. */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey('mixed.case@test.local'), 'log_count' => 3]);
        $this->assertDatabaseCount('ip_login_log', 2, []);
    }

    #[Test]
    public function different_accounts_have_independent_counters(): void
    {
        /* Act */
        $this->failLogin('alice@test.local');
        $this->failLogin('alice@test.local');
        $this->failLogin('bob@test.local');

        /* Assert */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey('alice@test.local'), 'log_count' => 2]);
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey('bob@test.local'), 'log_count' => 1]);
    }

    #[Test]
    public function submitting_another_throttles_key_as_an_email_never_creates_or_changes_a_counter(): void
    {
        /* Arrange: one ordinary failed login leaves an account counter and the IP counter. */
        $this->failLogin('probe@test.local');
        $ipKey = $this->findKey('login_ip:');
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $ipKey, 'log_count' => 1]);
        $rowsBefore = $this->databaseCount('ip_login_log');

        $forged = [
            $ipKey,
            'password_reset_email:' . hash('sha256', 'victim@test.local'),
            'password_reset_ip:' . hash('sha256', '0.0.0.0'),
            'cron_key:' . hash('sha256', '0.0.0.0'),
            'login_account:' . hash('sha256', 'probe@test.local'),
        ];

        /* Act: the attack — use each other counter's key as the "email". */
        foreach ($forged as $key) {
            $this->failLogin($key);
            $this->failLogin($key);
        }

        /* Assert: nothing was counted, so nothing could be pushed over a threshold. */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $ipKey, 'log_count' => 1]);
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey('probe@test.local'), 'log_count' => 1]);
        $this->assertDatabaseCount('ip_login_log', $rowsBefore, []);
        foreach (array_slice($forged, 1, 3) as $key) {
            $this->assertDatabaseMissing('ip_login_log', ['login_name' => $key]);
        }
    }

    #[Test]
    public function a_syntactically_valid_email_cannot_collide_with_a_throttle_key_either(): void
    {
        /* Arrange: even a well-formed address only ever reaches the "login_account:" namespace. */
        $email = 'login_ip@test.local';

        /* Act */
        $this->failLogin($email);

        /* Assert */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey($email), 'log_count' => 1]);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $email]);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => 'login_ip:' . hash('sha256', $email)]);
    }

    #[Test]
    public function ten_failures_lock_the_account_even_for_the_correct_password_and_leave_other_accounts_alone(): void
    {
        /* Arrange */
        $locked = 'locked-' . bin2hex(random_bytes(3)) . '@test.local';
        $other  = 'other-' . bin2hex(random_bytes(3)) . '@test.local';
        $this->createUser($locked, 'correct-password');
        $this->createUser($other, 'other-password');

        /* Act */
        for ($i = 0; $i < 10; $i++) {
            $this->failLogin($locked);
        }
        $this->post('/sessions/login', ['btn_login' => '1', 'email' => $locked, 'password' => 'correct-password']);
        $this->post('/sessions/login', ['btn_login' => '1', 'email' => $other, 'password' => 'other-password']);

        /* Assert: the correct password did not get through (auth() was never attempted, so the counter is not reset)... */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey($locked), 'log_count' => 10]);
        /* ...while the other account logged in fine and has no counter. */
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->accountKey($other)]);
    }

    #[Test]
    public function an_unknown_reset_token_is_counted_under_a_namespaced_digest_key_and_never_stored_in_the_clear(): void
    {
        /* Arrange */
        $token = 'abc123DEF456ghi789';

        /* Act */
        $this->get('/sessions/passwordreset/' . $token);

        /* Assert */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => 'password_reset_token:' . hash('sha256', $token), 'log_count' => 1]);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $token]);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => 'password_reset:' . hash('sha256', $token)]);
    }

    #[Test]
    public function a_token_shaped_like_a_throttle_key_cannot_be_used_as_one(): void
    {
        /* Arrange: the URL segment may only contain [alnum-_], so the ':' of a forged key is rejected outright. */
        $forged = 'login_ip:' . hash('sha256', '0.0.0.0');

        /* Act */
        $this->get('/sessions/passwordreset/' . rawurlencode($forged));

        /* Assert */
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $forged]);
    }

    #[Test]
    public function completing_a_password_reset_clears_the_accounts_login_lockout(): void
    {
        /* Arrange: a locked account with a valid reset token. */
        $email = 'reset-' . bin2hex(random_bytes(3)) . '@test.local';
        $token = 'resettoken' . bin2hex(random_bytes(6));
        $id    = $this->createUser($email, 'old-password-1');
        $this->databaseInsert('ip_login_log', ['login_name' => $this->accountKey($email), 'log_count' => 9, 'log_create_timestamp' => date('Y-m-d H:i:s')]);
        $this->databaseUpdate('ip_users', [
            'user_passwordreset_token'        => hash('sha256', $token),
            'user_passwordreset_token_expiry' => gmdate('Y-m-d H:i:s', time() + 600),
        ], ['user_id' => $id]);

        /* Act */
        $this->post('/sessions/passwordreset', [
            'btn_new_password' => '1',
            'user_id'          => (string) $id,
            'token'            => $token,
            'new_password'     => 'a-brand-new-password-2',
        ]);

        /* Assert */
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->accountKey($email)]);
    }

    private function failLogin(string $email): void
    {
        $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'definitely-wrong-password']);
    }

    private function accountKey(string $email): string
    {
        return 'login_account:' . hash('sha256', mb_strtolower($email));
    }

    private function findKey(string $prefix): string
    {
        foreach ($this->allLoginLogKeys() as $key) {
            if (str_starts_with($key, $prefix)) {
                return $key;
            }
        }

        self::fail('no ip_login_log row with prefix ' . $prefix);
    }

    /**
     * @return list<string>
     */
    private function allLoginLogKeys(): array
    {
        return array_map(static fn (array $row): string => (string) $row['login_name'], $this->databaseSelect('SELECT login_name FROM ip_login_log'));
    }

    private function createUser(string $email, string $password): int
    {
        return $this->databaseInsert('ip_users', [
            'user_name'          => 'Rate Limit Tester',
            'user_password'      => password_hash($password, PASSWORD_BCRYPT),
            'user_psalt'         => bin2hex(random_bytes(10)),
            'user_email'         => $email,
            'user_type'          => 1,
            'user_active'        => 1,
            'user_date_created'  => date('Y-m-d H:i:s'),
            'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
    }
}
