<?php

namespace Tests\Feature\Core\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Regression coverage for a gap found while porting the CWE-694 namespacing fix
 * (GHSA-r59m-wgg6-h4pv) to upstream: before that fix, ip_login_log rows for the
 * per-account lockout were keyed by the raw email address, and rows for the
 * password-reset token counter were keyed by the raw token. Deploying the fix
 * with no migration path means every row written under those pre-fix keys becomes
 * permanently unreachable the moment the namespaced lookup goes live — an account
 * that was actively locked out gets a free reset purely because the key format
 * changed underneath it, not because the lockout window elapsed.
 *
 * Sessions::authenticate() and the token-link branch of Sessions::passwordreset()
 * now migrate a matching legacy-keyed row onto the new namespaced key (preserving
 * log_count and log_create_timestamp) before checking it, the first time that
 * email/token is used after the fix deploys.
 */
#[Group('security')]
class LoginRateLimitLegacyKeyMigrationTest extends AbstractTestCase
{
    private const TOKEN = 'b2c3d4e5f60718293a4b5c6d7e8f901a2b3c4d5e6f708192a3b4c5d6e7f809a1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsGuest();
    }

    #[Test]
    public function it_preserves_an_active_account_lockout_recorded_under_the_pre_fix_raw_email_key(): void
    {
        /* Arrange: a pre-fix row — raw email as login_name, no prefix, no hash — at the
         * lockout threshold and still within the 12-hour window. The user's real password
         * is correct, so if the legacy lockout is lost this login would succeed. */
        $email = 'legacy-lockout@test.local';
        $this->seedLoginUser($email);
        $this->seedLoginLog($email, 10, '-5 minutes');

        /* Act */
        $response = $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'correct-password']);

        /* Assert: still locked out — the correct password must NOT be enough to log in,
         * because the legacy row's count (10) must carry over under the new key. */
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $email]);
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey($email), 'log_count' => 10]);
    }

    #[Test]
    public function it_migrates_a_below_threshold_legacy_account_row_and_still_allows_login(): void
    {
        /* Arrange: a legacy row that never reached the lockout threshold. */
        $email = 'legacy-below-threshold@test.local';
        $this->seedLoginUser($email);
        $this->seedLoginLog($email, 3, '-5 minutes');

        /* Act */
        $response = $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'correct-password']);

        /* Assert: login succeeds (below threshold), and the success path clears the
         * counter under its namespaced key — proving the row was migrated, not just
         * ignored, before being reset. */
        $this->assertResponseRedirectsToRoute($response, 'dashboard');
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $email]);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->accountKey($email)]);
    }

    #[Test]
    public function it_does_not_touch_a_legacy_row_for_a_different_email(): void
    {
        /* Arrange: a legacy lockout row for someone else entirely. */
        $this->seedLoginLog('someone-else@test.local', 10, '-5 minutes');
        $email = 'unrelated@test.local';
        $this->seedLoginUser($email);

        /* Act */
        $response = $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'correct-password']);

        /* Assert: unrelated legacy row is left exactly as it was. */
        $this->assertResponseRedirectsToRoute($response, 'dashboard');
        $this->assertDatabaseHas('ip_login_log', ['login_name' => 'someone-else@test.local', 'log_count' => 10]);
    }

    #[Test]
    public function it_preserves_an_active_token_lockout_recorded_under_the_pre_fix_prefixless_key(): void
    {
        /* Arrange: a pre-fix row — the raw token itself as login_name — at the lockout
         * threshold and still within the 12-hour window. */
        $this->seedUserWithResetToken(gmdate('Y-m-d H:i:s', time() + 600));
        $this->seedLoginLog(self::TOKEN, 11, '-5 minutes');

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . self::TOKEN);

        /* Assert: still locked out — the token-link flow must redirect away rather than
         * rendering the new-password form, because the legacy row's count (11, over the
         * >10 threshold) must carry over under the new namespaced key. */
        $this->assertResponseRedirectsToRoute($response, 'sessions/passwordreset');
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => self::TOKEN]);
        $this->assertDatabaseHas('ip_login_log', [
            'login_name' => 'password_reset_token:' . hash('sha256', self::TOKEN),
            'log_count'  => 11,
        ]);
    }

    #[Test]
    public function it_refuses_to_migrate_a_forged_email_shaped_like_another_counters_namespaced_key(): void
    {
        /* Arrange: the real per-IP counter already holds 19 attempts, and the attacker's
         * "email" is crafted to literally equal that counter's own namespaced key — the
         * same forgery the namespacing fix itself defends against. A naive migration step
         * that blindly renames "any row matching this literal string" onto the account key
         * would let the attacker steal the IP counter's row instead of starting fresh. */
        $this->seedLoginLog($this->ipKey(), 19, '-5 minutes');
        $forged_email = $this->ipKey();

        /* Act */
        $response = $this->post('/sessions/login', ['btn_login' => '1', 'email' => $forged_email, 'password' => 'irrelevant']);

        /* Assert: the real IP counter is untouched (still its own row, now 20 from the
         * genuine per-request IP tracking — not renamed away and not inflated to 21), and
         * the forged value gets its own fresh namespaced row instead of inheriting count 19. */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->ipKey(), 'log_count' => 20]);
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->accountKey($forged_email), 'log_count' => 1]);
    }

    private function ipKey(): string
    {
        return 'login_ip:' . hash('sha256', '127.0.0.1');
    }

    private function accountKey(string $email): string
    {
        return 'login_account:' . hash('sha256', mb_strtolower($email));
    }

    private function seedLoginLog(string $key, int $count, string $when): void
    {
        $this->databaseInsert('ip_login_log', [
            'login_name' => $key, 'log_count' => $count, 'log_create_timestamp' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    private function seedLoginUser(string $email): void
    {
        $this->databaseInsert('ip_users', [
            'user_name'         => 'Legacy Key Tester', 'user_password' => password_hash('correct-password', PASSWORD_BCRYPT),
            'user_psalt'        => bin2hex(random_bytes(10)), 'user_email' => $email, 'user_type' => 1, 'user_active' => 1,
            'user_date_created' => date('Y-m-d H:i:s'), 'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param string|null $expiry UTC 'Y-m-d H:i:s', or null for a legacy token with no expiry
     */
    private function seedUserWithResetToken(?string $expiry): int
    {
        return $this->databaseInsert('ip_users', [
            'user_name'                       => 'legacytoken_' . bin2hex(random_bytes(3)),
            'user_email'                      => 'legacytoken+' . bin2hex(random_bytes(3)) . '@example.com',
            'user_password'                   => password_hash('OriginalPass123!', PASSWORD_DEFAULT),
            'user_psalt'                      => bin2hex(random_bytes(10)),
            'user_type'                       => 1,
            'user_active'                     => 1,
            'user_passwordreset_token'        => hash('sha256', self::TOKEN),
            'user_passwordreset_token_expiry' => $expiry,
            'user_date_created'               => date('Y-m-d H:i:s'),
            'user_date_modified'              => date('Y-m-d H:i:s'),
        ]);
    }
}
