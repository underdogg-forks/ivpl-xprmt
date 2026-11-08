<?php

namespace Tests\Feature\Core\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * The password-reset guards of the Sessions controller, driven through real requests.
 *
 * These used to assert against StubSessionsSecurity, a copy of the logic declared inside the test file, so no
 * production code ran and a broken controller could not fail them. Every test below sends a request and reads
 * what the controller did: the redirect, the stored token and expiry, and the rows in ip_login_log.
 *
 * Covered: the token format guard, the stored expiry, the PASSWORD_RESET_TOKEN_EXPIRY_MINUTES cap, the bot
 * user-agent block and the per-IP rate limit (5 requests in 60 minutes). The referer guard is a helper and is
 * tested in tests/Unit/Core/Security/SecurityHelperTest.php.
 */
#[Group('security')]
#[Group('sessions')]
class SessionsSecurityTest extends AbstractTestCase
{
    private const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsGuest();
    }

    #[Test]
    public function it_accepts_an_alphanumeric_password_reset_token(): void
    {
        /* Arrange: a well-formed token that belongs to nobody */
        $token = 'abc123XYZ';

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . $token);

        /* Assert: it passed the format guard (the attempt was counted) and was then refused as unknown, not as malformed */
        $this->assertResponseRedirectsToRoute($response, 'sessions/passwordreset');
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->tokenKey($token), 'log_count' => 1]);
    }

    #[Test]
    public function it_accepts_a_hex_token_of_typical_length(): void
    {
        /* Arrange: a real 32-character hex token whose digest is stored with a future expiry */
        $token  = bin2hex(random_bytes(16));
        $userId = $this->seedUserWithResetToken($token, gmdate('Y-m-d H:i:s', time() + 900));

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . $token);

        /* Assert: the reset form is shown instead of a redirect, and the token is still usable */
        self::assertSame(200, $response->statusCode());
        self::assertSame(hash('sha256', $token), $this->tokenOf($userId));
    }

    #[Test]
    public function it_rejects_a_token_containing_a_path_traversal_sequence(): void
    {
        /* Arrange: dots are allowed in a URL segment, so ".." reaches the controller */
        $token = 'abc..def';

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . $token);

        /* Assert: sent to the home page, and the attempt never reached the token counter */
        $this->assertRedirectsToHome($response);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->tokenKey($token)]);
    }

    #[Test]
    public function it_rejects_a_token_containing_a_slash(): void
    {
        /* Arrange: an encoded slash decodes to a character outside the allowed set */
        $token = 'valid%2Finvalid';

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . $token);

        /* Assert */
        $this->assertRedirectsToHome($response);
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->tokenKey(rawurldecode($token))]);
    }

    #[Test]
    public function it_rejects_a_token_containing_special_characters(): void
    {
        /* Arrange: characters the URI filter lets through but the token format does not */
        foreach (['tok~en', 'tok:en', 'tok.en'] as $token) {
            /* Act */
            $response = $this->get('/sessions/passwordreset/' . $token);

            /* Assert */
            $this->assertRedirectsToHome($response);
            $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->tokenKey($token)]);
        }
    }

    #[Test]
    public function it_considers_an_expired_token_as_expired(): void
    {
        /* Arrange: a valid token whose expiry passed a minute ago */
        $token  = bin2hex(random_bytes(16));
        $userId = $this->seedUserWithResetToken($token, gmdate('Y-m-d H:i:s', time() - 60));

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . $token);

        /* Assert: refused with the generic message, and the token is burnt */
        $this->assertResponseRedirectsToRoute($response, 'sessions/passwordreset');
        self::assertSame('This password reset link has expired. Please request a new password reset.', $response->sessionValue('alert_error'));
        self::assertSame('', $this->tokenOf($userId));
    }

    #[Test]
    public function it_considers_a_future_token_as_not_expired(): void
    {
        /* Arrange: the same valid token, expiring in fifteen minutes */
        $token  = bin2hex(random_bytes(16));
        $userId = $this->seedUserWithResetToken($token, gmdate('Y-m-d H:i:s', time() + 900));

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . $token);

        /* Assert: the form is shown and the token is still usable */
        self::assertSame(200, $response->statusCode());
        self::assertSame(hash('sha256', $token), $this->tokenOf($userId));
    }

    #[Test]
    public function it_accepts_only_canonical_password_reset_expiry_strings(): void
    {
        /* Arrange: every expiry a DATETIME column can hold that is not a usable future timestamp */
        $cases = [
            'canonical future timestamp'           => [gmdate('Y-m-d H:i:s', time() + 900), true],
            'canonical timestamp in the past'      => ['2020-01-01 12:00:00', false],
            'zero date (right shape, unreal date)' => ['0000-00-00 00:00:00', false],
            'no stored expiry at all'              => [null, false],
        ];

        foreach ($cases as $label => [$expiry, $accepted]) {
            $token  = bin2hex(random_bytes(16));
            $userId = $this->seedUserWithResetToken($token, $expiry);

            /* Act */
            $response = $this->get('/sessions/passwordreset/' . $token);

            /* Assert */
            if ($accepted) {
                self::assertSame(200, $response->statusCode(), $label);
                self::assertSame(hash('sha256', $token), $this->tokenOf($userId), $label);
            } else {
                $this->assertResponseRedirectsToRoute($response, 'sessions/passwordreset');
                self::assertSame('', $this->tokenOf($userId), "{$label}: the token must be burnt");
            }
        }
    }

    #[Test]
    public function it_enforces_the_max_expiry_minutes_cap_of_1440(): void
    {
        /* Arrange: one minute past the 24 hour cap, and the cap itself */
        $beyond  = $this->requestResetWithExpiryMinutes('1441');
        $atLimit = $this->requestResetWithExpiryMinutes('1440');

        /* Assert: 1441 is refused and falls back to the 15 minute default, 1440 is honoured */
        self::assertEqualsWithDelta(15 * 60, $beyond, 120);
        self::assertEqualsWithDelta(1440 * 60, $atLimit, 120);
    }

    #[Test]
    public function it_allows_a_valid_expiry_minutes_value_within_range(): void
    {
        /* Arrange + Act */
        $seconds = $this->requestResetWithExpiryMinutes('45');

        /* Assert */
        self::assertEqualsWithDelta(45 * 60, $seconds, 120);
    }

    #[Test]
    public function it_rejects_a_zero_expiry_minutes_and_falls_back_to_default(): void
    {
        /* Arrange + Act: 0 would make every link dead on arrival */
        $seconds = $this->requestResetWithExpiryMinutes('0');

        /* Assert */
        self::assertEqualsWithDelta(15 * 60, $seconds, 120);
    }

    #[Test]
    public function it_detects_curl_as_a_bot_user_agent(): void
    {
        /* Arrange */
        $userId = $this->seedActiveUser('bot-curl@test.local');
        $this->withServer(['HTTP_USER_AGENT' => 'curl/7.85.0']);

        /* Act */
        $response = $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'bot-curl@test.local']);

        /* Assert: bounced to the login page, no token issued, and the rate limiter was not even touched */
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertSame('', $this->tokenOf($userId));
        $this->assertDatabaseMissing('ip_login_log', ['login_name' => $this->ipKey()]);
    }

    #[Test]
    public function it_detects_python_requests_as_a_bot_user_agent(): void
    {
        /* Arrange */
        $userId = $this->seedActiveUser('bot-python@test.local');
        $this->withServer(['HTTP_USER_AGENT' => 'python-requests/2.28.0']);

        /* Act */
        $response = $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'bot-python@test.local']);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertSame('', $this->tokenOf($userId));
    }

    #[Test]
    public function it_detects_an_empty_user_agent_as_a_bot(): void
    {
        /* Arrange: a request that sends no User-Agent header at all */
        $userId = $this->seedActiveUser('bot-empty@test.local');
        $this->withServer(['HTTP_USER_AGENT' => '']);

        /* Act */
        $response = $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'bot-empty@test.local']);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertSame('', $this->tokenOf($userId));
    }

    #[Test]
    public function it_does_not_flag_a_normal_browser_user_agent_as_a_bot(): void
    {
        /* Arrange: positive control, the identical request from a browser */
        $userId = $this->seedActiveUser('browser@test.local');
        $this->withServer(['HTTP_USER_AGENT' => self::BROWSER_USER_AGENT]);

        /* Act */
        $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'browser@test.local']);

        /* Assert: the same request that a bot loses gets a token */
        self::assertNotSame('', $this->tokenOf($userId), 'A browser must be allowed to request a reset.');
    }

    #[Test]
    public function it_removes_attempts_outside_the_rate_limit_time_window(): void
    {
        /* Arrange: the IP hit the limit two hours ago, far outside the 60 minute window */
        $userId = $this->seedActiveUser('old-attempts@test.local');
        $this->seedLog($this->ipKey(), 5, '-2 hours');

        /* Act */
        $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'old-attempts@test.local']);

        /* Assert: the stale counter is not held against the request, and the count starts again at 1 */
        self::assertNotSame('', $this->tokenOf($userId));
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->ipKey(), 'log_count' => 1]);
    }

    #[Test]
    public function it_considers_the_ip_rate_limited_when_attempt_count_meets_the_threshold(): void
    {
        /* Arrange: exactly 5 attempts counted for this IP just now */
        $userId = $this->seedActiveUser('limited@test.local');
        $this->seedLog($this->ipKey(), 5, 'now');

        /* Act */
        $response = $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'limited@test.local']);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertSame('', $this->tokenOf($userId));
    }

    #[Test]
    public function it_does_not_rate_limit_when_attempt_count_is_below_the_threshold(): void
    {
        /* Arrange: 4 attempts, one below the limit */
        $userId = $this->seedActiveUser('not-limited@test.local');
        $this->seedLog($this->ipKey(), 4, 'now');

        /* Act */
        $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'not-limited@test.local']);

        /* Assert */
        self::assertNotSame('', $this->tokenOf($userId));
        $this->assertDatabaseHas('ip_login_log', ['login_name' => $this->ipKey(), 'log_count' => 5]);
    }

    private function requestResetWithExpiryMinutes(string $minutes): int
    {
        $this->withEnvironment(['PASSWORD_RESET_TOKEN_EXPIRY_MINUTES' => $minutes]);
        $email  = 'expiry-' . $minutes . '-' . bin2hex(random_bytes(3)) . '@test.local';
        $userId = $this->seedActiveUser($email);

        $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => $email]);

        $expiry = (string) ($this->databaseFetchOne('ip_users', ['user_id' => $userId])['user_passwordreset_token_expiry'] ?? '');
        self::assertNotSame('', $expiry, 'A reset request must store a token expiry.');

        return (new DateTimeImmutable($expiry, new DateTimeZone('UTC')))->getTimestamp() - time();
    }

    private function assertRedirectsToHome(\Tests\Integration\Support\HttpResponse $response): void
    {
        self::assertTrue($response->isRedirect(), 'Expected a redirect, got status ' . $response->statusCode() . '.');
        self::assertContains(trim((string) parse_url($response->redirectUrl(), PHP_URL_PATH), '/'), ['', 'index.php'], 'A malformed token must send the visitor to the home page.');
    }

    private function tokenKey(string $token): string
    {
        return 'password_reset_token:' . hash('sha256', $token);
    }

    private function ipKey(): string
    {
        return 'password_reset_ip:' . hash('sha256', '127.0.0.1');
    }

    private function tokenOf(int $userId): string
    {
        return (string) ($this->databaseFetchOne('ip_users', ['user_id' => $userId])['user_passwordreset_token'] ?? '');
    }

    private function seedLog(string $key, int $count, string $when): void
    {
        $this->databaseInsert('ip_login_log', [
            'login_name' => $key, 'log_count' => $count, 'log_create_timestamp' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    private function seedActiveUser(string $email): int
    {
        return $this->databaseInsert('ip_users', [
            'user_name'         => 'Reset Tester', 'user_password' => password_hash('irrelevant-pw', PASSWORD_BCRYPT),
            'user_psalt'        => bin2hex(random_bytes(10)), 'user_email' => $email, 'user_type' => 1, 'user_active' => 1,
            'user_date_created' => date('Y-m-d H:i:s'), 'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedUserWithResetToken(string $token, ?string $expiry): int
    {
        return $this->databaseInsert('ip_users', [
            'user_name'                       => 'reset_' . bin2hex(random_bytes(3)),
            'user_email'                      => 'reset+' . bin2hex(random_bytes(3)) . '@example.com',
            'user_password'                   => password_hash('OriginalPass123!', PASSWORD_BCRYPT),
            'user_psalt'                      => bin2hex(random_bytes(10)),
            'user_type'                       => 1,
            'user_active'                     => 1,
            'user_passwordreset_token'        => hash('sha256', $token),
            'user_passwordreset_token_expiry' => $expiry,
            'user_date_created'               => date('Y-m-d H:i:s'),
            'user_date_modified'              => date('Y-m-d H:i:s'),
        ]);
    }
}
