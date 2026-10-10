<?php

namespace Tests\Feature\Users;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

final class UserClientsIdorTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    #[Test]
    public function it_denies_a_secondary_admin_creating_a_client_mapping_for_another_user(): void
    {
        /* Arrange */
        $secondaryId = $this->seedAdmin();
        $victimId    = $this->seedAdmin();
        $clientId    = $this->seedClient();
        $this->actingAsAdmin($secondaryId);

        /* Act */
        $response = $this->postWithValidCsrfToken('/user_clients/create/' . $victimId, [
            'user_id'   => $victimId,
            'client_id' => $clientId,
        ]);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseMissing('ip_user_clients', ['user_id' => $victimId]);
    }

    #[Test]
    public function it_allows_the_primary_admin_to_create_a_client_mapping_for_another_user(): void
    {
        /* Arrange */
        $targetId = $this->seedAdmin();
        $clientId = $this->seedClient();
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->postWithValidCsrfToken('/user_clients/create/' . $targetId, [
            'user_id'   => $targetId,
            'client_id' => $clientId,
        ]);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'user_clients/user/' . $targetId);
        $this->assertDatabaseHas('ip_user_clients', ['user_id' => $targetId, 'client_id' => $clientId]);
    }

    #[Test]
    public function it_denies_a_secondary_admin_deleting_another_users_client_mapping(): void
    {
        /* Arrange */
        $secondaryId = $this->seedAdmin();
        $victimId    = $this->seedAdmin();
        $clientId    = $this->seedClient();
        $mappingId   = $this->databaseInsert('ip_user_clients', ['user_id' => $victimId, 'client_id' => $clientId]);
        $this->actingAsAdmin($secondaryId);

        /* Act */
        $response = $this->postWithValidCsrfToken('/user_clients/delete/' . $mappingId);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseHas('ip_user_clients', ['user_client_id' => $mappingId]);
    }

    #[Test]
    public function it_denies_a_secondary_admin_granting_themselves_all_clients(): void
    {
        /* Arrange */
        $secondaryId = $this->seedAdmin();
        $clientId    = $this->seedClient();
        $this->actingAsAdmin($secondaryId);

        /* Act */
        $response = $this->postWithValidCsrfToken('/user_clients/create/' . $secondaryId, [
            'user_id'          => $secondaryId,
            'client_id'        => $clientId,
            'user_all_clients' => '1',
        ]);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseHas('ip_users', ['user_id' => $secondaryId, 'user_all_clients' => 0]);
    }

    #[Test]
    public function it_allows_the_primary_admin_to_grant_all_clients(): void
    {
        /* Arrange */
        $targetId = $this->seedAdmin();
        $clientId = $this->seedClient();
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->postWithValidCsrfToken('/user_clients/create/' . $targetId, [
            'user_id'          => $targetId,
            'client_id'        => $clientId,
            'user_all_clients' => '1',
        ]);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'user_clients/user/' . $targetId);
        $this->assertDatabaseHas('ip_users', ['user_id' => $targetId, 'user_all_clients' => 1]);
    }

    #[Test]
    public function it_rejects_a_non_integer_shaped_user_id_in_the_create_url(): void
    {
        /*
         * Regression for a CodeRabbit finding ported from upstream PR #1747's own hardening
         * commit (6c40b66e): a non-integer-shaped id such as "2.9" would authorize against
         * user 2 via the (int) cast in the authorization check, while the raw, uncast value
         * is what actually reaches $this->db->where('user_id', $user_id) and the model's
         * save() — a type-confusion gap between what's authorized and what's written.
         */

        /* Arrange */
        $clientId = $this->seedClient();
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->postWithValidCsrfToken('/user_clients/create/2.9', [
            'user_id'   => '2.9',
            'client_id' => $clientId,
        ]);

        /* Assert: rejected outright, not silently truncated to user 2. */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseMissing('ip_user_clients', ['client_id' => $clientId]);
    }

    #[Test]
    public function it_rejects_a_non_integer_shaped_posted_user_id_in_the_create_url(): void
    {
        /* Arrange: the URL id is a genuine integer the acting admin may manage, but the
         * POSTed user_id is non-integer-shaped. */
        $targetId = $this->seedAdmin();
        $clientId = $this->seedClient();
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->postWithValidCsrfToken('/user_clients/create/' . $targetId, [
            'user_id'   => $targetId . '.9',
            'client_id' => $clientId,
        ]);

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseMissing('ip_user_clients', ['client_id' => $clientId]);
    }

    private function seedAdmin(): int
    {
        return $this->databaseInsert('ip_users', [
            'user_type'          => 1,
            'user_name'          => 'Admin ' . bin2hex(random_bytes(3)),
            'user_email'         => 'admin+' . bin2hex(random_bytes(4)) . '@test.local',
            'user_password'      => password_hash('secret123', PASSWORD_DEFAULT),
            'user_psalt'         => bin2hex(random_bytes(8)),
            'user_language'      => 'system',
            'user_active'        => 1,
            'user_date_created'  => date('Y-m-d H:i:s'),
            'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
    }
}
