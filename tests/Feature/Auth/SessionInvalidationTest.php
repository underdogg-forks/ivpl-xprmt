<?php

namespace Tests\Feature\Auth;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

final class SessionInvalidationTest extends AbstractTestCase
{
    #[Test]
    public function it_accepts_a_session_bound_to_the_current_password(): void
    {
        /* Arrange */
        $userId = $this->seedAdmin();
        $this->actingAsAdmin($userId);

        /* Act */
        $response = $this->get('/supplier_invoices');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        self::assertNotSame('', $response->body(), 'The protected page must actually render.');
    }

    #[Test]
    public function it_rejects_an_old_session_after_the_password_changes(): void
    {
        /* Arrange */
        $userId = $this->seedAdmin();
        $this->actingAsAdmin($userId);
        $before = $this->get('/supplier_invoices');
        $this->databaseUpdate('ip_users', [
            'user_password'     => password_hash('NewPassword456!', PASSWORD_DEFAULT),
            'user_auth_version' => 1,
        ], ['user_id' => $userId]);

        /* Act */
        $response = $this->get('/supplier_invoices');

        /* Assert: the same session worked until the password changed, then no longer does */
        $this->assertResponseOk($before);
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertSame('', $response->body(), 'The protected page must not be rendered for a stale session.');
    }

    #[Test]
    public function it_rejects_an_old_session_when_only_the_password_hash_changes(): void
    {
        /* Arrange */
        $userId = $this->seedAdmin();
        $this->actingAsAdmin($userId);
        $before = $this->get('/supplier_invoices');
        $this->databaseUpdate('ip_users', [
            'user_password' => password_hash('AnotherPassword789!', PASSWORD_DEFAULT),
        ], ['user_id' => $userId]);

        /* Act */
        $response = $this->get('/supplier_invoices');

        /* Assert */
        $this->assertResponseOk($before);
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertSame('', $response->body(), 'The protected page must not be rendered for a stale session.');
    }

    #[Test]
    public function it_ends_another_sessions_access_after_changing_a_password_through_the_real_endpoint(): void
    {
        /*
         * The three tests above simulate "the password changed" by UPDATE-ing ip_users
         * directly, so none of them ever call Users::change_password() itself. This
         * exercises the real controller endpoint instead of its downstream effect.
         */

        /* Arrange: two independent sessions for the same account (e.g. two browsers) */
        $userId          = $this->seedAdmin();
        $attackerSession = ['user_id' => (string) $userId, 'user_type' => '1', 'user_email' => 'admin@test.local', 'user_name' => 'Test Admin', 'user_company' => 'Test Co', 'user_language' => 'system'];
        $this->actingAsAdmin($userId);
        $attackerSession['user_auth_version'] = $this->sessionData['user_auth_version'];
        $attackerSession['user_credential']   = $this->sessionData['user_credential'];

        /* Act: the account's own session changes the password through the real endpoint */
        $response = $this->post('/users/change_password/' . $userId, [
            'user_password'  => 'BrandNewPassword123!',
            'user_passwordv' => 'BrandNewPassword123!',
        ]);
        self::assertTrue($response->isRedirect(), 'A valid password change must redirect.');

        /* Assert: the OTHER session of the same account is rejected on its next request */
        $this->sessionData = $attackerSession;
        $rejected          = $this->get('/supplier_invoices');
        $this->assertResponseRedirectsToRoute($rejected, 'sessions/login');
        self::assertSame('', $rejected->body(), 'A session that did not make the change must not survive it.');
    }

    #[Test]
    public function it_preserves_the_acting_users_own_session_after_changing_their_own_password(): void
    {
        /* Arrange */
        $userId = $this->seedAdmin();
        $this->actingAsAdmin($userId);

        /* Act: the acting user changes their own password through the real endpoint */
        $changeResponse = $this->post('/users/change_password/' . $userId, [
            'user_password'  => 'BrandNewPassword123!',
            'user_passwordv' => 'BrandNewPassword123!',
        ]);
        self::assertTrue($changeResponse->isRedirect(), 'A valid password change must redirect.');

        /* Assert: carrying the session forward (as a real browser cookie would), the
         * SAME session that just changed its own password must still be authorized —
         * not caught by the very invalidation it just triggered for every other session. */
        $this->sessionData = $changeResponse->session();
        $response          = $this->get('/supplier_invoices');
        $this->assertResponseStatusCode($response, 200);
        self::assertNotSame('', $response->body(), 'The acting user must not be logged out by their own password change.');
    }

    private function seedAdmin(): int
    {
        return $this->databaseInsert('ip_users', [
            'user_type'          => 1,
            'user_name'          => 'Admin ' . bin2hex(random_bytes(3)),
            'user_email'         => 'admin+' . bin2hex(random_bytes(4)) . '@test.local',
            'user_password'      => password_hash('OldPassword123!', PASSWORD_DEFAULT),
            'user_psalt'         => bin2hex(random_bytes(8)),
            'user_language'      => 'system',
            'user_active'        => 1,
            'user_date_created'  => date('Y-m-d H:i:s'),
            'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
    }
}
