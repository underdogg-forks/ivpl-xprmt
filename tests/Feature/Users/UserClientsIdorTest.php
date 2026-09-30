<?php

namespace Tests\Feature\Users;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-5rqm-w9p8-gp7w (PR #1747): after Users/Ajax::save_user_client() got a primary-administrator-or-
 * owner check, User_Clients::create(), User_Clients::delete() and Users::delete_user_client() still let
 * any administrator grant or revoke any user's client assignments — and, by posting user_all_clients
 * for their own account, bulk-assign every client in the system to themselves (read access to every
 * invoice, quote and payment).
 *
 * Actors: the seeded primary administrator (user_id 1), a secondary administrator (the attacker) and a
 * victim administrator. Every test drives the real endpoints and asserts on ip_user_clients / ip_users.
 */
#[Group('security')]
class UserClientsIdorTest extends AbstractTestCase
{
    private int $attackerId;

    private int $victimId;

    private int $clientA;

    private int $clientB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attackerId = $this->seedAdmin('attacker@test.local');
        $this->victimId   = $this->seedAdmin('victim@test.local');
        $this->clientA    = $this->seedClient(['client_name' => 'Client A']);
        $this->clientB    = $this->seedClient(['client_name' => 'Client B']);
    }

    // -------------------------------------------------------------------------
    // create
    // -------------------------------------------------------------------------

    #[Test]
    public function a_secondary_admin_cannot_assign_a_client_to_another_user(): void
    {
        /* Arrange */
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/create/' . $this->victimId, ['user_id' => (string) $this->victimId, 'client_id' => (string) $this->clientA]);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseMissing('ip_user_clients', ['user_id' => $this->victimId]);
    }

    #[Test]
    public function a_secondary_admin_cannot_smuggle_another_users_id_in_the_post_body(): void
    {
        /* Arrange: the URL names the attacker, but the model saves the POSTed user_id. */
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/create/' . $this->attackerId, ['user_id' => (string) $this->victimId, 'client_id' => (string) $this->clientA]);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseCount('ip_user_clients', 0, []);
    }

    #[Test]
    public function a_secondary_admin_can_still_manage_their_own_client_list(): void
    {
        /* Arrange */
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/create/' . $this->attackerId, ['user_id' => (string) $this->attackerId, 'client_id' => (string) $this->clientA]);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_user_clients', ['user_id' => $this->attackerId, 'client_id' => $this->clientA]);
    }

    #[Test]
    public function the_primary_admin_can_assign_a_client_to_anyone(): void
    {
        /* Arrange */
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->post('/user_clients/create/' . $this->victimId, ['user_id' => (string) $this->victimId, 'client_id' => (string) $this->clientA]);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_user_clients', ['user_id' => $this->victimId, 'client_id' => $this->clientA]);
    }

    // -------------------------------------------------------------------------
    // user_all_clients — the privilege-escalation primitive
    // -------------------------------------------------------------------------

    #[Test]
    public function a_secondary_admin_cannot_grant_themselves_every_client(): void
    {
        /* Arrange */
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/create/' . $this->attackerId, ['user_id' => (string) $this->attackerId, 'client_id' => (string) $this->clientA, 'user_all_clients' => '1']);

        /* Assert: no flag, and none of the per-client rows set_all_clients_user() would have bulk-inserted. */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseHas('ip_users', ['user_id' => $this->attackerId, 'user_all_clients' => 0]);
        $this->assertDatabaseMissing('ip_user_clients', ['user_id' => $this->attackerId]);
    }

    #[Test]
    public function a_secondary_admin_cannot_flip_the_all_clients_flag_on_someone_else(): void
    {
        /* Arrange */
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/create/' . $this->victimId, ['user_id' => (string) $this->victimId, 'client_id' => (string) $this->clientA, 'user_all_clients' => '1']);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseHas('ip_users', ['user_id' => $this->victimId, 'user_all_clients' => 0]);
    }

    #[Test]
    public function the_primary_admin_can_still_grant_the_all_clients_flag(): void
    {
        /* Arrange */
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->post('/user_clients/create/' . $this->victimId, ['user_id' => (string) $this->victimId, 'client_id' => (string) $this->clientA, 'user_all_clients' => '1']);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_users', ['user_id' => $this->victimId, 'user_all_clients' => 1]);
        $this->assertDatabaseHas('ip_user_clients', ['user_id' => $this->victimId, 'client_id' => $this->clientA]);
        $this->assertDatabaseHas('ip_user_clients', ['user_id' => $this->victimId, 'client_id' => $this->clientB]);
    }

    // -------------------------------------------------------------------------
    // delete (User_Clients::delete)
    // -------------------------------------------------------------------------

    #[Test]
    public function a_secondary_admin_cannot_revoke_another_users_assignment(): void
    {
        /* Arrange */
        $mapping = $this->assign($this->victimId, $this->clientA);
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/delete/' . $mapping);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseHas('ip_user_clients', ['user_client_id' => $mapping]);
    }

    #[Test]
    public function a_secondary_admin_can_revoke_their_own_assignment(): void
    {
        /* Arrange */
        $mapping = $this->assign($this->attackerId, $this->clientA);
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/delete/' . $mapping);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseMissing('ip_user_clients', ['user_client_id' => $mapping]);
    }

    #[Test]
    public function the_primary_admin_can_revoke_anyones_assignment(): void
    {
        /* Arrange */
        $mapping = $this->assign($this->victimId, $this->clientA);
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->post('/user_clients/delete/' . $mapping);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseMissing('ip_user_clients', ['user_client_id' => $mapping]);
    }

    #[Test]
    public function deleting_a_nonexistent_assignment_is_a_404_not_a_server_error(): void
    {
        /* Arrange */
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/user_clients/delete/999999');

        /* Assert */
        $this->assertResponseStatusCode($response, 404);
    }

    // -------------------------------------------------------------------------
    // delete via the users module (Users::delete_user_client)
    // -------------------------------------------------------------------------

    #[Test]
    public function a_secondary_admin_cannot_revoke_a_victims_assignment_through_the_users_endpoint(): void
    {
        /* Arrange */
        $mapping = $this->assign($this->victimId, $this->clientA);
        $this->actingAsAdmin($this->attackerId);

        /* Act: once naming the victim in the URL, once naming themselves. */
        $viaVictim   = $this->post('/users/delete_user_client/' . $this->victimId . '/' . $mapping);
        $viaAttacker = $this->post('/users/delete_user_client/' . $this->attackerId . '/' . $mapping);

        /* Assert */
        $this->assertResponseStatusCode($viaVictim, 403);
        $this->assertResponseStatusCode($viaAttacker, 403);
        $this->assertDatabaseHas('ip_user_clients', ['user_client_id' => $mapping]);
    }

    #[Test]
    public function the_users_endpoint_refuses_an_assignment_that_does_not_belong_to_the_url_user(): void
    {
        /* Arrange: even the primary admin must name the right owner, so a stale or tampered URL deletes nothing. */
        $mapping = $this->assign($this->victimId, $this->clientA);
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->post('/users/delete_user_client/' . $this->attackerId . '/' . $mapping);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseHas('ip_user_clients', ['user_client_id' => $mapping]);
    }

    #[Test]
    public function the_users_endpoint_still_deletes_the_owners_own_assignment(): void
    {
        /* Arrange */
        $mapping = $this->assign($this->attackerId, $this->clientA);
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->post('/users/delete_user_client/' . $this->attackerId . '/' . $mapping);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseMissing('ip_user_clients', ['user_client_id' => $mapping]);
    }

    // -------------------------------------------------------------------------
    // The already-fixed AJAX endpoint stays fixed
    // -------------------------------------------------------------------------

    #[Test]
    public function the_ajax_assignment_endpoint_still_refuses_another_users_id(): void
    {
        /* Arrange */
        $this->actingAsAdmin($this->attackerId);

        /* Act */
        $response = $this->request('POST', '/users/ajax/save_user_client', [], ['user_id' => (string) $this->victimId, 'client_id' => (string) $this->clientA], true);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseMissing('ip_user_clients', ['user_id' => $this->victimId]);
    }

    // -------------------------------------------------------------------------
    // Not logged in / wrong role
    // -------------------------------------------------------------------------

    #[Test]
    public function an_unauthenticated_request_changes_nothing(): void
    {
        /* Arrange */
        $mapping = $this->assign($this->victimId, $this->clientA);
        $this->actingAsGuest();

        /* Act */
        $create = $this->post('/user_clients/create/' . $this->victimId, ['user_id' => (string) $this->victimId, 'client_id' => (string) $this->clientB]);
        $delete = $this->post('/user_clients/delete/' . $mapping);

        /* Assert */
        self::assertTrue($create->isRedirect());
        self::assertTrue($delete->isRedirect());
        $this->assertDatabaseHas('ip_user_clients', ['user_client_id' => $mapping]);
        $this->assertDatabaseCount('ip_user_clients', 1, []);
    }

    #[Test]
    public function a_read_only_guest_user_cannot_reach_the_endpoints(): void
    {
        /* Arrange */
        $guestId = $this->seedAdmin('guest-user@test.local', 2);
        $mapping = $this->assign($this->victimId, $this->clientA);
        $this->actingAs(['user_id' => $guestId, 'user_type' => 2, 'user_email' => 'guest-user@test.local']);

        /* Act */
        $response = $this->post('/user_clients/delete/' . $mapping);

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_user_clients', ['user_client_id' => $mapping]);
    }

    private function seedAdmin(string $email, int $type = 1): int
    {
        return (int) $this->seedModel('User', ['user_type' => $type, 'user_email' => $email])->user_id;
    }

    private function assign(int $userId, int $clientId): int
    {
        return $this->databaseInsert('ip_user_clients', ['user_id' => $userId, 'client_id' => $clientId]);
    }
}
