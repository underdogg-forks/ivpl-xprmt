<?php

namespace Tests\Feature\Core\Users;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;
use Users;

/**
 * Users::form() object-level authorization (CWE-639 / CWE-269): the primary administrator may edit anyone, a peer
 * administrator only their own account. Without it a peer rewrote the primary administrator's email and then took the
 * account over through password recovery, or demoted the primary administrator to a read-only guest.
 *
 * Run under the production CSRF configuration. Each denial is paired with the same request from the primary
 * administrator, so a refusal cannot be an artefact of an invalid payload.
 */
#[Group('users')]
#[Group('security')]
#[CoversClass(Users::class)]
final class UsersFormAuthorizationTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfProtection();
    }

    #[Test]
    public function it_denies_a_peer_admin_rewriting_the_primary_admins_email_and_role(): void
    {
        /* Arrange */
        $peerId = $this->seedUser(1, 'peer-form');
        $before = $this->databaseFetchOne('ip_users', ['user_id' => 1]);
        $this->actingAsAdmin($peerId);

        /* Act */
        $response = $this->postWithValidCsrfToken('/users/form/1', $this->payload('Hijacked Admin', 'attacker@evil.example', 2));

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $after = $this->databaseFetchOne('ip_users', ['user_id' => 1]);
        foreach (['user_name', 'user_email', 'user_type', 'user_password', 'user_active'] as $field) {
            self::assertSame($before[$field], $after[$field], "{$field} of the primary administrator must not change.");
        }
    }

    #[Test]
    public function it_denies_a_peer_admin_editing_another_peer(): void
    {
        /* Arrange */
        $peerId   = $this->seedUser(1, 'peer-editor');
        $victimId = $this->seedUser(1, 'peer-victim');
        $this->actingAsAdmin($peerId);

        /* Act */
        $response = $this->postWithValidCsrfToken('/users/form/' . $victimId, $this->payload('Renamed By Peer', 'peer-victim@test.local', 1));

        /* Assert */
        $this->assertResponseStatusCode($response, 403);
        $this->assertDatabaseHas('ip_users', ['user_id' => $victimId, 'user_name' => 'peer-victim']);
    }

    #[Test]
    public function it_lets_the_primary_admin_edit_a_peer(): void
    {
        /* Arrange: positive control for the two denials above */
        $peerId = $this->seedUser(1, 'peer-editable');
        $this->actingAsAdmin(1);

        /* Act */
        $response = $this->postWithValidCsrfToken('/users/form/' . $peerId, $this->payload('Edited By Primary', 'peer-editable@test.local', 1));

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'users');
        $this->assertDatabaseHas('ip_users', ['user_id' => $peerId, 'user_name' => 'Edited By Primary']);
    }

    #[Test]
    public function it_lets_a_peer_admin_edit_their_own_account(): void
    {
        /* Arrange */
        $peerId = $this->seedUser(1, 'peer-self');
        $this->actingAsAdmin($peerId);

        /* Act */
        $response = $this->postWithValidCsrfToken('/users/form/' . $peerId, $this->payload('Peer Self Renamed', 'peer-self@test.local', 1));

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'users');
        $this->assertDatabaseHas('ip_users', ['user_id' => $peerId, 'user_name' => 'Peer Self Renamed', 'user_type' => 1]);
    }

    #[Test]
    public function it_ignores_a_role_change_in_the_form_a_user_submits_for_their_own_account(): void
    {
        /* Arrange: a role changes only when somebody else edits the account, never through the owner's own form */
        $peerId = $this->seedUser(1, 'peer-self-role');
        $this->actingAsAdmin($peerId);

        /* Act */
        $response = $this->postWithValidCsrfToken('/users/form/' . $peerId, $this->payload('peer-self-role', 'peer-self-role@test.local', 2));

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'users');
        $this->assertDatabaseHas('ip_users', ['user_id' => $peerId, 'user_type' => 1]);
    }

    #[Test]
    public function it_ends_the_access_of_a_user_the_primary_admin_demotes(): void
    {
        /* Arrange: the peer holds a live administrator session */
        $peerId = $this->seedUser(1, 'peer-demoted');
        $this->actingAsAdmin($peerId);
        $peerSession = $this->sessionData;
        self::assertSame(200, $this->get('/users')->statusCode(), 'The peer reaches an administrator page before the demotion.');

        /* Act: the primary administrator demotes the peer to a guest */
        $this->actingAsAdmin(1);
        $response = $this->postWithValidCsrfToken('/users/form/' . $peerId, $this->payload('peer-demoted', 'peer-demoted@test.local', 2));

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'users');
        $this->assertDatabaseHas('ip_users', ['user_id' => $peerId, 'user_type' => 2]);
        $this->sessionData = $peerSession;
        $this->assertResponseRedirectsToRoute($this->get('/users'), 'sessions/login');
    }

    #[Test]
    public function it_deletes_the_session_files_of_a_user_whose_role_changes_and_only_theirs(): void
    {
        /* Arrange: a private session folder with one stored session per user, in the files driver's own format */
        $dir = sys_get_temp_dir() . '/ip_users_form_sessions_' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        $this->withEnvironment(['SESS_SAVE_PATH' => $dir]);
        $demotedId     = $this->seedUser(1, 'peer-session-demoted');
        $bystanderId   = $this->seedUser(1, 'peer-session-bystander');
        $demotedFile   = $dir . '/ip_session' . bin2hex(random_bytes(8));
        $bystanderFile = $dir . '/ip_session' . bin2hex(random_bytes(8));
        file_put_contents($demotedFile, 'user_id|' . serialize((string) $demotedId));
        file_put_contents($bystanderFile, 'user_id|' . serialize((string) $bystanderId));
        $this->actingAsAdmin(1);

        try {
            /* Act */
            $response = $this->postWithValidCsrfToken('/users/form/' . $demotedId, $this->payload('peer-session-demoted', 'peer-session-demoted@test.local', 2));

            /* Assert */
            $this->assertResponseRedirectsToRoute($response, 'users');
            self::assertFileDoesNotExist($demotedFile, 'The demoted user\'s stored session must be destroyed.');
            self::assertFileExists($bystanderFile, 'Another user\'s session must survive.');
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    #[Test]
    public function it_keeps_the_session_files_of_a_user_whose_role_does_not_change(): void
    {
        /* Arrange: positive control, an edit that leaves the role alone destroys nothing */
        $dir = sys_get_temp_dir() . '/ip_users_form_sessions_' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        $this->withEnvironment(['SESS_SAVE_PATH' => $dir]);
        $peerId   = $this->seedUser(1, 'peer-session-kept');
        $peerFile = $dir . '/ip_session' . bin2hex(random_bytes(8));
        file_put_contents($peerFile, 'user_id|' . serialize((string) $peerId));
        $this->actingAsAdmin(1);

        try {
            /* Act */
            $response = $this->postWithValidCsrfToken('/users/form/' . $peerId, $this->payload('Renamed Only', 'peer-session-kept@test.local', 1));

            /* Assert */
            $this->assertResponseRedirectsToRoute($response, 'users');
            self::assertFileExists($peerFile);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    private function payload(string $name, string $email, int $type): array
    {
        return [
            'user_type'     => (string) $type,
            'user_name'     => $name,
            'user_email'    => $email,
            'user_language' => 'system',
            'user_active'   => '1',
            'btn_submit'    => '1',
        ];
    }

    private function seedUser(int $type, string $name): int
    {
        return $this->databaseInsert('ip_users', [
            'user_type'          => $type,
            'user_name'          => $name,
            'user_email'         => $name . '@test.local',
            'user_password'      => password_hash('secret-' . $name, PASSWORD_DEFAULT),
            'user_psalt'         => bin2hex(random_bytes(8)),
            'user_language'      => 'system',
            'user_active'        => 1,
            'user_date_created'  => date('Y-m-d H:i:s'),
            'user_date_modified' => date('Y-m-d H:i:s'),
        ]);
    }
}
