<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * Mdl_Users::save() is the data-layer backstop for the "secondary administrator rewrites the primary administrator"
 * class of findings (email takeover through password recovery, and demotion to guest). Users::form() refuses such a
 * request with a 403, so the form tests never reach this guard: it only matters for a caller that skips the form.
 * These tests call the model directly, with the acting user taken from the session.
 */
#[Group('security')]
#[Group('users')]
final class UsersPrimaryAdminLockedFieldsTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_strips_the_privilege_and_identity_fields_when_a_peer_admin_saves_the_primary_admin(): void
    {
        /* Arrange */
        $before  = $this->databaseFetchOne('ip_users', ['user_id' => 1]);
        $peerId  = $this->databaseInsert('ip_users', $this->userRow('peer-admin'));
        $model   = $this->usersModelActingAs($peerId);
        $payload = [
            'user_type'     => 2,
            'user_active'   => 0,
            'user_email'    => 'attacker-controlled@evil.example',
            'user_password' => 'attacker-hash',
            'user_psalt'    => 'attacker-salt',
            'user_name'     => 'Renamed By Peer',
        ];

        /* Act */
        $model->save(1, $payload);

        /* Assert: the locked fields are untouched, the field that is not locked is the only change */
        $after = $this->databaseFetchOne('ip_users', ['user_id' => 1]);
        foreach (['user_type', 'user_active', 'user_email', 'user_password', 'user_psalt'] as $locked) {
            self::assertSame($before[$locked], $after[$locked], "{$locked} of the primary administrator must not change.");
        }
        self::assertSame('Renamed By Peer', $after['user_name']);
    }

    #[Test]
    public function it_lets_the_primary_admin_change_their_own_locked_fields(): void
    {
        /* Arrange: positive control, the same payload from the primary administrator is applied in full */
        $model = $this->usersModelActingAs(1);

        /* Act */
        $model->save(1, ['user_email' => 'primary-new@test.local', 'user_name' => 'Primary Renamed']);

        /* Assert */
        $this->assertDatabaseHas('ip_users', ['user_id' => 1, 'user_email' => 'primary-new@test.local', 'user_name' => 'Primary Renamed']);
    }

    #[Test]
    public function it_does_not_strip_fields_when_a_peer_admin_saves_another_user(): void
    {
        /* Arrange: the backstop is about user_id 1 only; a peer admin saving a third user is the form's decision, not the model's */
        $peerId   = $this->databaseInsert('ip_users', $this->userRow('peer-two'));
        $targetId = $this->databaseInsert('ip_users', $this->userRow('third-user'));
        $model    = $this->usersModelActingAs($peerId);

        /* Act */
        $model->save($targetId, ['user_email' => 'third-new@test.local']);

        /* Assert */
        $this->assertDatabaseHas('ip_users', ['user_id' => $targetId, 'user_email' => 'third-new@test.local']);
    }

    private function usersModelActingAs(int $userId): object
    {
        require_once APPPATH . 'helpers/trans_helper.php';

        $ci       = $this->bootCodeIgniter();
        $ci->lang = new class () {
            public function line(string $key): string
            {
                return $key;
            }
        };
        $ci->session = new class ($userId) {
            public function __construct(private int $userId) {}

            public function userdata(string $key): mixed
            {
                return $key === 'user_id' ? (string) $this->userId : null;
            }

            public function unset_userdata(string $key): void {}

            public function set_flashdata(string $key, mixed $value): void {}
        };

        return $this->ciModel('users/models/Mdl_users');
    }

    private function userRow(string $name): array
    {
        return [
            'user_type'          => 1,
            'user_name'          => $name,
            'user_email'         => $name . '@test.local',
            'user_password'      => password_hash('secret-' . $name, PASSWORD_DEFAULT),
            'user_psalt'         => bin2hex(random_bytes(8)),
            'user_language'      => 'system',
            'user_active'        => 1,
            'user_date_created'  => date('Y-m-d H:i:s'),
            'user_date_modified' => date('Y-m-d H:i:s'),
        ];
    }
}
