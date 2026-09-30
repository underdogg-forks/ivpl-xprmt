<?php

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-hg6g-m4fp-7cj3 (PR #1746): changing or resetting a password did not end sessions that were
 * opened with the old password. Sessions now carry an HMAC fingerprint of the password hash they were
 * created with (session_credential_fingerprint()), and User_Controller compares it with the current
 * hash on every request.
 *
 * The request harness injects the session on each call, so a "stale session" is a session whose
 * fingerprint was computed from the old hash — exactly what a browser cookie from before the change
 * would carry.
 */
#[Group('security')]
class SessionInvalidationTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once ROOT_PATH . '/application/helpers/ip_security_helper.php';
        $GLOBALS['unitCiConfig']['encryption_key'] = (string) env('ENCRYPTION_KEY');
    }

    #[Test]
    public function a_session_bound_to_the_current_password_is_accepted(): void
    {
        /* Arrange */
        [$id, $hash] = $this->createAdmin('current@test.local', 'Current-password-1');
        $this->actAs($id, $hash);

        /* Act */
        $response = $this->get('/users');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
    }

    #[Test]
    public function changing_a_users_password_ends_their_older_sessions_but_not_the_new_one(): void
    {
        /* Arrange: user 5 has an open session, and the primary admin changes their password. */
        [$id, $oldHash] = $this->createAdmin('victim@test.local', 'Old-password-1');
        $this->actAs($id, $oldHash);
        $this->assertResponseStatusCode($this->get('/users'), 200);

        $this->actingAsAdmin(1);
        $this->post('/users/change_password/' . $id, ['user_password' => 'New-password-2', 'user_passwordv' => 'New-password-2']);

        $newHash = (string) $this->databaseFetchOne('ip_users', ['user_id' => $id])['user_password'];
        self::assertNotSame($oldHash, $newHash, 'precondition: the password hash must have changed');

        /* Act */
        $this->actAs($id, $oldHash);
        $stale = $this->get('/users');

        $this->actAs($id, $newHash);
        $fresh = $this->get('/users');

        /* Assert */
        self::assertTrue($stale->isRedirect(), 'the session opened with the old password must be sent to the login page');
        $this->assertResponseBodyNotContains($stale, 'victim@test.local');
        $this->assertResponseStatusCode($fresh, 200);
    }

    #[Test]
    public function a_password_reset_by_token_also_ends_older_sessions(): void
    {
        /* Arrange */
        [$id, $oldHash] = $this->createAdmin('reset@test.local', 'Old-password-1');
        $token          = 'resettoken' . bin2hex(random_bytes(6));
        $this->databaseUpdate('ip_users', [
            'user_passwordreset_token'        => hash('sha256', $token),
            'user_passwordreset_token_expiry' => gmdate('Y-m-d H:i:s', time() + 600),
        ], ['user_id' => $id]);

        $this->actingAsGuest();
        $this->post('/sessions/passwordreset', [
            'btn_new_password' => '1',
            'user_id'          => (string) $id,
            'token'            => $token,
            'new_password'     => 'Reset-password-2',
        ]);
        self::assertNotSame($oldHash, (string) $this->databaseFetchOne('ip_users', ['user_id' => $id])['user_password'], 'precondition: the reset must have changed the hash');

        /* Act */
        $this->actAs($id, $oldHash);
        $response = $this->get('/users');

        /* Assert */
        self::assertTrue($response->isRedirect());
    }

    #[Test]
    public function a_guest_portal_session_is_also_ended_when_the_password_changes(): void
    {
        /* Arrange: a read-only guest user (type 2) with a session bound to the old hash. */
        [$id, $oldHash] = $this->createUser('guest@test.local', 'Old-password-1', 2);
        $this->databaseInsert('ip_user_clients', ['user_id' => $id, 'client_id' => $this->seedClient()]);
        $this->actAs($id, $oldHash, 2);
        $this->assertResponseStatusCode($this->get('/guest/invoices/status/all'), 200);

        $this->databaseUpdate('ip_users', ['user_password' => password_hash('New-password-2', PASSWORD_BCRYPT)], ['user_id' => $id]);

        /* Act */
        $this->actAs($id, $oldHash, 2);
        $response = $this->get('/guest/invoices/status/all');

        /* Assert */
        self::assertTrue($response->isRedirect());
    }

    #[Test]
    public function changing_one_users_password_leaves_other_users_sessions_alone(): void
    {
        /* Arrange */
        [$aliceId, $aliceHash] = $this->createAdmin('alice@test.local', 'Alice-password-1');
        [$bobId, $bobHash]     = $this->createAdmin('bob@test.local', 'Bob-password-1');

        $this->databaseUpdate('ip_users', ['user_password' => password_hash('Alice-new-2', PASSWORD_BCRYPT)], ['user_id' => $aliceId]);

        /* Act */
        $this->actAs($bobId, $bobHash);
        $bob = $this->get('/users');
        $this->actAs($aliceId, $aliceHash);
        $alice = $this->get('/users');

        /* Assert */
        $this->assertResponseStatusCode($bob, 200);
        self::assertTrue($alice->isRedirect());
    }

    #[Test]
    public function a_session_with_a_forged_or_truncated_fingerprint_is_rejected(): void
    {
        /* Arrange */
        [$id, $hash] = $this->createAdmin('forged@test.local', 'Some-password-1');
        $genuine     = session_credential_fingerprint($hash);

        foreach (['not-a-fingerprint', substr($genuine, 0, 32), strtoupper($genuine), $genuine . '0', hash('sha256', $hash)] as $forged) {
            $this->actAs($id, $hash);
            $this->sessionData['user_credential'] = $forged;

            /* Act */
            $response = $this->get('/users');

            /* Assert */
            self::assertTrue($response->isRedirect(), 'fingerprint "' . $forged . '" must not be accepted');
        }
    }

    #[Test]
    public function the_fingerprint_is_a_keyed_digest_not_derived_from_the_bare_hash(): void
    {
        /* Arrange */
        $hash = password_hash('anything-1', PASSWORD_BCRYPT);

        /* Act */
        $fingerprint = session_credential_fingerprint($hash);

        /* Assert */
        self::assertSame(64, mb_strlen($fingerprint));
        self::assertNotSame(hash('sha256', $hash), $fingerprint, 'must be keyed with the encryption key');
        self::assertStringNotContainsString($hash, $fingerprint);
        self::assertSame($fingerprint, session_credential_fingerprint($hash), 'deterministic for the same hash');
        self::assertNotSame($fingerprint, session_credential_fingerprint($hash . 'x'), 'different hash, different fingerprint');
    }

    #[Test]
    public function a_session_created_before_fingerprints_existed_is_still_accepted_once(): void
    {
        /* Arrange: no user_credential at all, as for a session opened before the upgrade. */
        [$id] = $this->createAdmin('legacy@test.local', 'Legacy-password-1');
        $this->actingAs(['user_id' => $id, 'user_type' => 1, 'user_email' => 'legacy@test.local']);

        /* Act */
        $response = $this->get('/users');

        /* Assert: it is bound to the current password on this request rather than logging everyone out on upgrade. */
        $this->assertResponseStatusCode($response, 200);
    }

    #[Test]
    public function a_deactivated_account_is_still_locked_out_even_with_a_matching_fingerprint(): void
    {
        /* Arrange */
        [$id, $hash] = $this->createAdmin('inactive@test.local', 'Some-password-1');
        $this->databaseUpdate('ip_users', ['user_active' => 0], ['user_id' => $id]);
        $this->actAs($id, $hash);

        /* Act */
        $response = $this->get('/users');

        /* Assert */
        self::assertTrue($response->isRedirect());
    }

    /**
     * @return array{int, string} user id and the stored password hash
     */
    private function createAdmin(string $email, string $password): array
    {
        return $this->createUser($email, $password, 1);
    }

    /**
     * @return array{int, string}
     */
    private function createUser(string $email, string $password, int $type): array
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $id   = $this->databaseInsert('ip_users', [
            'user_name'          => 'Session Tester',
            'user_password'      => $hash,
            'user_psalt'         => bin2hex(random_bytes(10)),
            'user_email'         => $email,
            'user_type'          => $type,
            'user_active'        => 1,
            'user_date_created'  => date('Y-m-d H:i:s'),
            'user_date_modified' => date('Y-m-d H:i:s'),
        ]);

        return [$id, $hash];
    }

    private function actAs(int $id, string $passwordHash, int $type = 1): void
    {
        $this->actingAs(['user_id' => $id, 'user_type' => $type, 'user_email' => 'session@test.local']);
        $this->sessionData['user_credential'] = session_credential_fingerprint($passwordHash);
    }
}
