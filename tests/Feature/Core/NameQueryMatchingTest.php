<?php

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\SwitchesSqlMode;

/**
 * What clients/ajax/name_query and users/ajax/name_query match, with ONLY_FULL_GROUP_BY enabled on the server.
 *
 * Both used HAVING on plain columns (MariaDB error 1463) and now filter in WHERE. Moving the conditions changes
 * how they combine with the active / type conditions, and the client full name stops being a select-list alias,
 * so these tests pin each branch of the OR group and the AND around it.
 */
#[Group('clients')]
#[Group('users')]
final class NameQueryMatchingTest extends AbstractTestCase
{
    use SwitchesSqlMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->switchSqlMode('ONLY_FULL_GROUP_BY');
    }

    protected function tearDown(): void
    {
        $this->restoreSqlMode();
        parent::tearDown();
    }

    #[Test]
    public function it_runs_these_tests_under_only_full_group_by(): void
    {
        /* Assert: the premise, read back from the server the app connects to */
        self::assertStringContainsString('ONLY_FULL_GROUP_BY', $this->currentGlobalSqlMode());
    }

    // -------------------------------------------------------------------------
    // clients/ajax/name_query
    // -------------------------------------------------------------------------

    #[Test]
    public function it_finds_a_client_by_the_start_of_the_first_name_the_surname_or_the_full_name(): void
    {
        /* Arrange: only the full name "Ada Lovelace" contains the space-joined term below */
        $ada   = $this->seedClient(['client_name' => 'Ada', 'client_surname' => 'Lovelace']);
        $other = $this->seedClient(['client_name' => 'Grace', 'client_surname' => 'Hopper']);

        /* Act */
        $byFirst   = $this->clientIds('Ad');
        $bySurname = $this->clientIds('Love');
        $byFull    = $this->clientIds('Ada Lov');

        /* Assert: each branch of the OR group finds Ada and never the other client */
        self::assertSame([$ada], $byFirst);
        self::assertSame([$ada], $bySurname);
        self::assertSame([$ada], $byFull);
        self::assertNotContains($other, array_merge($byFirst, $bySurname, $byFull));
    }

    #[Test]
    public function it_does_not_find_a_client_whose_full_name_only_matches_across_the_wrong_boundary(): void
    {
        /* Arrange */
        $this->seedClient(['client_name' => 'Ada', 'client_surname' => 'Lovelace']);

        /* Act: "AdaLov" would match if the two names were joined without the space */
        $ids = $this->clientIds('AdaLov');

        /* Assert */
        self::assertSame([], $ids);
    }

    #[Test]
    public function it_never_returns_an_inactive_client_whichever_branch_matches(): void
    {
        /* Arrange: the OR group must be ANDed with client_active, not merged into it */
        $this->seedClient(['client_name' => 'Ada', 'client_surname' => 'Lovelace', 'client_active' => 0]);
        $active = $this->seedClient(['client_name' => 'Ada', 'client_surname' => 'Byron', 'client_active' => 1]);

        /* Act */
        $byFirst   = $this->clientIds('Ad');
        $bySurname = $this->clientIds('Love');
        $byFull    = $this->clientIds('Ada Lov');

        /* Assert: the inactive client is absent on all three; the active one is found by first name */
        self::assertSame([$active], $byFirst);
        self::assertSame([], $bySurname);
        self::assertSame([], $byFull);
    }

    #[Test]
    public function it_matches_inside_a_client_name_only_when_permissive_search_is_requested(): void
    {
        /* Arrange */
        $ada = $this->seedClient(['client_name' => 'Ada', 'client_surname' => 'Lovelace']);

        /* Act */
        $strict     = $this->clientIds('velace');
        $permissive = $this->clientIds('velace', ['permissive_search_clients' => '1']);

        /* Assert */
        self::assertSame([], $strict);
        self::assertSame([$ada], $permissive);
    }

    // -------------------------------------------------------------------------
    // users/ajax/name_query
    // -------------------------------------------------------------------------

    #[Test]
    public function it_finds_a_user_by_the_start_of_the_name_the_company_or_the_invoicing_contact(): void
    {
        /* Arrange: three users, each reachable through exactly one column */
        $byName    = $this->seedUser(['user_type' => 1, 'user_name' => 'Nina Name', 'user_company' => 'Acme', 'user_active' => 1]);
        $byCompany = $this->seedUser(['user_type' => 1, 'user_name' => 'Cora', 'user_company' => 'Globex Corp', 'user_active' => 1]);
        $byContact = $this->seedUser(['user_type' => 1, 'user_name' => 'Ivo', 'user_company' => 'Initech', 'user_invoicing_contact' => 'Billing Bob', 'user_active' => 1]);

        /* Act */
        $name    = $this->userIds('Nina');
        $company = $this->userIds('Globex');
        $contact = $this->userIds('Billing');

        /* Assert */
        self::assertSame([$byName], $name);
        self::assertSame([$byCompany], $company);
        self::assertSame([$byContact], $contact);
    }

    #[Test]
    public function it_returns_only_active_users_of_the_requested_type_whichever_column_matches(): void
    {
        /* Arrange: the match is in user_company, the column the OR group reaches last */
        $match = $this->seedUser(['user_type' => 1, 'user_name' => 'Ann', 'user_company' => 'Zeta Works', 'user_active' => 1]);
        $this->seedUser(['user_type' => 1, 'user_name' => 'Inactive', 'user_company' => 'Zeta Inactive', 'user_active' => 0]);
        $this->seedUser(['user_type' => 2, 'user_name' => 'Guest', 'user_company' => 'Zeta Guest', 'user_active' => 1]);

        /* Act */
        $admins = $this->userIds('Zeta', 1);
        $guests = $this->userIds('Zeta', 2);

        /* Assert: the OR group is ANDed with active and type */
        self::assertSame([$match], $admins);
        self::assertCount(1, $guests);
        self::assertNotContains($match, $guests);
    }

    /**
     * Inserts a user; the unique email and the password columns are filled in.
     *
     * @param array<string,mixed> $overrides
     */
    private function seedUser(array $overrides = []): int
    {
        return $this->databaseInsert('ip_users', array_merge([
            'user_type'         => 2, 'user_name' => 'Seeded ' . bin2hex(random_bytes(3)),
            'user_email'        => 'seed+' . bin2hex(random_bytes(4)) . '@test.local',
            'user_password'     => password_hash('secret123', PASSWORD_DEFAULT), 'user_psalt' => bin2hex(random_bytes(8)),
            'user_language'     => 'system', 'user_active' => 1,
            'user_date_created' => date('Y-m-d H:i:s'), 'user_date_modified' => date('Y-m-d H:i:s'),
        ], $overrides));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return int[]
     */
    private function clientIds(string $query, array $extra = []): array
    {
        $response = $this->request('GET', '/clients/ajax/name_query', ['query' => $query] + $extra, [], true);
        $this->assertResponseStatusCode($response, 200);

        return array_map(intval(...), array_column(json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR), 'id'));
    }

    /**
     * @return int[]
     */
    private function userIds(string $query, int $type = 1): array
    {
        $response = $this->request('GET', '/users/ajax/name_query/' . $type, ['query' => $query], [], true);
        $this->assertResponseStatusCode($response, 200);

        return array_map(intval(...), array_column(json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR), 'id'));
    }
}
