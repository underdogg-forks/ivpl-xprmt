<?php

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SwitchesSqlMode;

/**
 * Every UsersAjaxControllerTest test, run again with ONLY_FULL_GROUP_BY enabled on the server. MariaDB rejects
 * HAVING on plain columns without a GROUP BY (error 1463), which broke users/ajax/name_query.
 */
#[Group('users')]
final class UsersAjaxOnlyFullGroupByTest extends UsersAjaxControllerTest
{
    use SwitchesSqlMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->switchSqlMode('ONLY_FULL_GROUP_BY');
    }

    protected function tearDown(): void
    {
        $this->restoreSqlMode();
        parent::tearDown();
    }

    #[Test]
    public function it_runs_the_inherited_tests_under_only_full_group_by(): void
    {
        /* Assert: the premise, read back from the server the app connects to */
        self::assertStringContainsString('ONLY_FULL_GROUP_BY', $this->currentGlobalSqlMode());
    }
}
