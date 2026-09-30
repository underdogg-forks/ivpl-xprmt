<?php

namespace Tests\Feature\Core\Security;

use Cryptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Exploit-style regression probes for security advisories whose fix had no behavioural test.
 * Each test performs the attack the advisory describes against the running application and
 * asserts that it no longer works.
 */
#[Group('security')]
class AdvisoryRegressionTest extends AbstractTestCase
{
    // -------------------------------------------------------------------------
    // GHSA-2m4-962x-cphw: Sessions::authenticate() reachable as a URL
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function authenticateUrls(): array
    {
        return [
            'GET, clean path segments'     => ['GET', '/sessions/authenticate/admin/password'],
            'POST, clean path segments'    => ['POST', '/sessions/authenticate/admin/password'],
            'GET with an @ in the path'    => ['GET', '/sessions/authenticate/admin@test.local/password'],
            'POST with an @ in the path'   => ['POST', '/sessions/authenticate/admin@test.local/password'],
            'GET with url-encoded address' => ['GET', '/sessions/authenticate/admin%40test.local/password'],
            'GET with only one segment'    => ['GET', '/sessions/authenticate/admin'],
        ];
    }

    // -------------------------------------------------------------------------
    // GHSA-p5w-98m2-gvc8 / GHSA-x5h... / GHSA-fq5-mw9f-mv6j: setup wizard after installation
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function setupUrls(): array
    {
        return [
            'index'            => ['GET', '/setup'],
            'prerequisites'    => ['GET', '/setup/prerequisites'],
            'configure db'     => ['GET', '/setup/configure_database'],
            'install tables'   => ['GET', '/setup/install_tables'],
            'upgrade tables'   => ['GET', '/setup/upgrade_tables'],
            'post db settings' => ['POST', '/setup/configure_database'],
            'post install'     => ['POST', '/setup/install_tables'],
            'post upgrade'     => ['POST', '/setup/upgrade_tables'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function logoTypes(): array
    {
        return ['invoice logo' => ['invoice'], 'login logo' => ['login']];
    }

    // -------------------------------------------------------------------------
    // GHSA-7f2-mj3r-xx88: SQL injection through the users name_query autocomplete
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function sqlPayloads(): array
    {
        return [
            'quote or true' => ["x' OR '1'='1"],
            'comment out'   => ["x'-- -"],
            'union'         => ["x' UNION SELECT user_password,1,1 FROM ip_users-- -"],
            'stacked'       => ["x'; DROP TABLE ip_users;-- -"],
        ];
    }

    #[Test]
    #[DataProvider('authenticateUrls')]
    public function the_authenticate_method_cannot_be_called_as_a_url(string $method, string $uri): void
    {
        /* Arrange */
        $this->actingAsGuest();

        /* Act */
        $response = $this->request($method, $uri);

        /* Assert: refused outright (404, or 400 from the router's URI character filter) — never a login. */
        self::assertContains($response->statusCode(), [400, 404], 'authenticate() must not be routable, got ' . $response->statusCode());
        self::assertFalse($response->isRedirect(), 'authenticate() must not log anyone in or redirect');
    }

    #[Test]
    #[DataProvider('setupUrls')]
    public function the_setup_wizard_is_locked_once_setup_is_completed(string $method, string $uri): void
    {
        /* Arrange */
        $this->actingAsGuest();
        $this->withEnvironment(['SETUP_COMPLETED' => 'true']);
        $versionsBefore = $this->databaseCount('ip_versions');

        /* Act */
        $response = $this->request($method, $uri, [], $method === 'POST' ? ['db_hostname' => 'evil.example', 'db_database' => 'x', 'btn_continue' => '1'] : []);

        /* Assert */
        self::assertNotSame(200, $response->statusCode(), 'the wizard must not render after installation');
        $this->assertResponseBodyNotContains($response, 'evil.example');
        $this->assertSame($versionsBefore, $this->databaseCount('ip_versions'), 'no migration may run');
    }

    // -------------------------------------------------------------------------
    // GHSA-r28-6rw3-25c2 / GHSA-wj7-c84x-jvjq: password-reset token expiry
    // -------------------------------------------------------------------------

    #[Test]
    public function an_expired_reset_token_cannot_change_the_password(): void
    {
        /* Arrange */
        $this->actingAsGuest();
        [$id, $hash, $token] = $this->userWithResetToken(gmdate('Y-m-d H:i:s', time() - 3600));

        /* Act */
        $this->post('/sessions/passwordreset', ['btn_new_password' => '1', 'user_id' => (string) $id, 'token' => $token, 'new_password' => 'Attacker-chosen-1']);

        /* Assert */
        self::assertSame($hash, (string) $this->databaseFetchOne('ip_users', ['user_id' => $id])['user_password'], 'an expired token must not change the password');
    }

    #[Test]
    public function an_expired_reset_token_does_not_open_the_new_password_form(): void
    {
        /* Arrange */
        $this->actingAsGuest();
        [, , $token] = $this->userWithResetToken(gmdate('Y-m-d H:i:s', time() - 3600));

        /* Act */
        $response = $this->get('/sessions/passwordreset/' . $token);

        /* Assert */
        $this->assertResponseBodyNotContains($response, 'new_password');
    }

    #[Test]
    public function a_valid_reset_token_still_works_once_and_only_once(): void
    {
        /* Arrange */
        $this->actingAsGuest();
        [$id, $hash, $token] = $this->userWithResetToken(gmdate('Y-m-d H:i:s', time() + 600));

        /* Act */
        $this->post('/sessions/passwordreset', ['btn_new_password' => '1', 'user_id' => (string) $id, 'token' => $token, 'new_password' => 'Legit-new-password-1']);
        $afterFirst = (string) $this->databaseFetchOne('ip_users', ['user_id' => $id])['user_password'];
        $this->post('/sessions/passwordreset', ['btn_new_password' => '1', 'user_id' => (string) $id, 'token' => $token, 'new_password' => 'Replay-attempt-2']);
        $afterReplay = (string) $this->databaseFetchOne('ip_users', ['user_id' => $id])['user_password'];

        /* Assert */
        self::assertNotSame($hash, $afterFirst, 'the first use changes the password');
        self::assertSame($afterFirst, $afterReplay, 'the token is consumed; replaying it changes nothing');
    }

    // -------------------------------------------------------------------------
    // GHSA-5r4-8w63-c5h2: guest "paid" list leaked other clients' invoices
    // -------------------------------------------------------------------------

    #[Test]
    public function a_guest_only_sees_their_own_clients_paid_invoices(): void
    {
        /* Arrange: the other client's zero-balance invoice must not satisfy the OR arm. */
        $mine   = $this->seedClient(['client_name' => 'Mine Ltd']);
        $theirs = $this->seedClient(['client_name' => 'Theirs Ltd']);
        $this->seedInvoice($mine, ['invoice_status_id' => 4, 'invoice_number' => 'INV-MINE-PAID'], ['invoice_balance' => '0.00']);
        $this->seedInvoice($theirs, ['invoice_status_id' => 4, 'invoice_number' => 'INV-THEIRS-PAID'], ['invoice_balance' => '0.00']);
        $this->seedInvoice($theirs, ['invoice_status_id' => 2, 'invoice_number' => 'INV-THEIRS-ZERO'], ['invoice_balance' => '0.00']);

        $guestId = (int) $this->seedModel('User', ['user_type' => 2, 'user_email' => 'guest-scope@test.local'])->user_id;
        $this->databaseInsert('ip_user_clients', ['user_id' => $guestId, 'client_id' => $mine]);
        $this->actingAs(['user_id' => $guestId, 'user_type' => 2, 'user_email' => 'guest-scope@test.local']);

        /* Act */
        $response = $this->get('/guest/invoices/status/paid');

        /* Assert */
        $this->assertResponseBodyContains($response, 'INV-MINE-PAID');
        $this->assertResponseBodyNotContains($response, 'INV-THEIRS-PAID');
        $this->assertResponseBodyNotContains($response, 'INV-THEIRS-ZERO');
    }

    // -------------------------------------------------------------------------
    // GHSA-pr3-c7rf-gc7x / GHSA-57j-c733-6h5f: state-changing GET on generate_pdf
    // -------------------------------------------------------------------------

    #[Test]
    public function a_forged_get_does_not_mark_a_draft_invoice_as_sent_or_number_it(): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        $this->enableMarkSentOnPdf('invoices');
        $id     = $this->seedInvoice($this->seedClient(), ['invoice_status_id' => 1, 'invoice_number' => '']);
        $before = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $id]);

        /* Act: <img src="/invoices/generate_pdf/ID"> from another site carries no CSRF token. */
        $this->get('/invoices/generate_pdf/' . $id);

        /* Assert */
        $after = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $id]);
        self::assertSame(1, (int) $after['invoice_status_id'], 'the invoice must stay a draft');
        self::assertSame((string) $before['invoice_number'], (string) $after['invoice_number'], 'no invoice number may be assigned');
    }

    #[Test]
    public function a_forged_get_does_not_mark_a_draft_quote_as_sent(): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        $this->enableMarkSentOnPdf('quotes');
        $quote = $this->seedModel('Quote', ['client_id' => $this->seedClient(), 'quote_status_id' => 1]);

        /* Act */
        $this->get('/quotes/generate_pdf/' . $quote->quote_id);

        /* Assert */
        self::assertSame(1, (int) $this->databaseFetchOne('ip_quotes', ['quote_id' => $quote->quote_id])['quote_status_id']);
    }

    // -------------------------------------------------------------------------
    // GHSA-x8h-35wf-cgqf: GET-based logo removal
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('logoTypes')]
    public function a_forged_get_cannot_remove_a_logo(string $type): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $type . '_logo', 'setting_value' => 'keep-me.png']);
        $this->databaseUpdate('ip_settings', ['setting_value' => 'keep-me.png'], ['setting_key' => $type . '_logo']);

        /* Act */
        $this->get('/settings/remove_logo/' . $type);

        /* Assert */
        $this->assertDatabaseHas('ip_settings', ['setting_key' => $type . '_logo', 'setting_value' => 'keep-me.png']);
    }

    // -------------------------------------------------------------------------
    // GHSA-543-x4j8-jj4q: decrypted gateway secrets in the settings HTML
    // -------------------------------------------------------------------------

    #[Test]
    public function the_settings_page_never_contains_a_stored_gateway_secret_in_clear(): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        require_once ROOT_PATH . '/application/libraries/Cryptor.php';
        $secret = 'sk_live_TOPSECRET_' . bin2hex(random_bytes(6));
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_stripe_apiKey', 'setting_value' => Cryptor::Encrypt($secret, (string) env('ENCRYPTION_KEY'))]);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_stripe_enabled', 'setting_value' => '1']);

        /* Act */
        $response = $this->get('/settings');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyNotContains($response, $secret);
    }

    #[Test]
    #[DataProvider('sqlPayloads')]
    public function the_user_autocomplete_is_not_injectable(string $payload): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        $this->seedModel('User', ['user_type' => 1, 'user_name' => 'Zed Unique', 'user_email' => 'zed@test.local']);

        /* Act */
        $response = $this->request('GET', '/users/ajax/name_query', ['query' => $payload], [], true);

        /* Assert: no SQL error, nothing returned for a payload that matches no name, and the table survived. */
        $this->assertResponseStatusCode($response, 200);
        self::assertSame([], json_decode($response->body(), true), 'a payload must be treated as a literal search term');
        self::assertTrue($this->databaseTableExists('ip_users'));
    }

    // -------------------------------------------------------------------------
    // GHSA-7w2-hmm5-qw7m / GHSA-cxg-3465-jc8m: rate limits must not live in the session
    // -------------------------------------------------------------------------

    #[Test]
    public function the_password_reset_throttle_counts_requests_that_carry_no_session_cookie(): void
    {
        /* Arrange: every request below arrives with no cookies at all, i.e. a brand-new session. */
        $this->actingAsGuest();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'enable_password_reset', 'setting_value' => '1']);

        /* Act */
        for ($i = 0; $i < 4; $i++) {
            $this->post('/sessions/passwordreset', ['btn_reset' => '1', 'email' => 'victim@test.local']);
        }

        /* Assert: the counters are in the database, shared across sessions. */
        $ip = $this->databaseFetchOne('ip_login_log', ['login_name' => 'password_reset_ip:' . hash('sha256', '127.0.0.1')]);
        $em = $this->databaseFetchOne('ip_login_log', ['login_name' => 'password_reset_email:' . hash('sha256', 'victim@test.local')]);
        self::assertNotNull($ip, 'the per-IP counter must be persisted server-side');
        self::assertNotNull($em, 'the per-email counter must be persisted server-side');
        self::assertGreaterThanOrEqual(2, (int) $ip['log_count']);
        self::assertGreaterThanOrEqual(2, (int) $em['log_count']);
    }

    // -------------------------------------------------------------------------
    // GHSA-jwm-qx8w-hrq2: the lockout must expire
    // -------------------------------------------------------------------------

    #[Test]
    public function an_account_lockout_expires_after_twelve_hours(): void
    {
        /* Arrange: a user locked out (10 failures) 13 hours ago. */
        $this->actingAsGuest();
        $email = 'lockout-' . bin2hex(random_bytes(3)) . '@test.local';
        $this->databaseInsert('ip_users', [
            'user_name'  => 'Locked', 'user_password' => password_hash('Correct-password-1', PASSWORD_BCRYPT), 'user_psalt' => bin2hex(random_bytes(10)),
            'user_email' => $email, 'user_type' => 1, 'user_active' => 1, 'user_date_created' => date('Y-m-d H:i:s'), 'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
        $this->databaseInsert('ip_login_log', [
            'login_name' => 'login_account:' . hash('sha256', $email), 'log_count' => 10, 'log_create_timestamp' => date('Y-m-d H:i:s', time() - 13 * 3600),
        ]);

        /* Act */
        $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'wrong-password-now']);

        /* Assert: the stale lock was lifted and this failure counted as the first of a new window. */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => 'login_account:' . hash('sha256', $email), 'log_count' => 1]);
    }

    #[Test]
    public function a_lockout_younger_than_twelve_hours_still_blocks(): void
    {
        /* Arrange */
        $this->actingAsGuest();
        $email = 'lockout2-' . bin2hex(random_bytes(3)) . '@test.local';
        $this->databaseInsert('ip_login_log', [
            'login_name' => 'login_account:' . hash('sha256', $email), 'log_count' => 10, 'log_create_timestamp' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        /* Act */
        $this->post('/sessions/login', ['btn_login' => '1', 'email' => $email, 'password' => 'anything']);

        /* Assert */
        $this->assertDatabaseHas('ip_login_log', ['login_name' => 'login_account:' . hash('sha256', $email), 'log_count' => 10]);
    }

    // -------------------------------------------------------------------------
    // GHSA-6fv-2f29-rc6g: a PENDING PayPal capture is not settled money
    // -------------------------------------------------------------------------

    // (see PaymentGatewayCaptureSafetyTest — PENDING handling is asserted there)

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{int, string, string} user id, stored password hash, plaintext token
     */
    private function userWithResetToken(string $expiryUtc): array
    {
        $token = 'resettoken' . bin2hex(random_bytes(6));
        $hash  = password_hash('Original-password-1', PASSWORD_BCRYPT);
        $id    = $this->databaseInsert('ip_users', [
            'user_name'         => 'Reset Tester', 'user_password' => $hash, 'user_psalt' => bin2hex(random_bytes(10)),
            'user_email'        => 'reset-' . bin2hex(random_bytes(3)) . '@test.local', 'user_type' => 1, 'user_active' => 1,
            'user_date_created' => date('Y-m-d H:i:s'), 'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
        $this->databaseUpdate('ip_users', ['user_passwordreset_token' => hash('sha256', $token), 'user_passwordreset_token_expiry' => $expiryUtc], ['user_id' => $id]);

        return [$id, $hash, $token];
    }

    private function enableMarkSentOnPdf(string $module): void
    {
        $key = 'mark_' . $module . '_sent_pdf';
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $key, 'setting_value' => '1']);
        $this->databaseUpdate('ip_settings', ['setting_value' => '1'], ['setting_key' => $key]);
        $this->withEnvironment(['CSRF_PROTECTION' => 'true']);
    }
}
