<?php

namespace Tests\Unit\Core\Sessions;

use Crypt;
use Mdl_Sessions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\AbstractTestCase;
use Tests\Concerns\UsesCodeIgniterModels;

/**
 * Mdl_Sessions::auth() previously returned false immediately when no row
 * matched the submitted email, skipping the bcrypt comparison that the
 * existing-account path always runs. That made a non-existent email's
 * response measurably faster, letting an attacker enumerate registered
 * emails by timing alone despite the uniform "credentials incorrect"
 * message (CWE-208 / CWE-204).
 *
 * Runs the real Mdl_Sessions model in-process (no HTTP subprocess, to keep
 * the timing signal free of request-harness overhead) with a real Crypt
 * library instance, so these tests exercise the production code path.
 */
#[Group('unit')]
#[Group('security')]
#[Group('sessions')]
class LoginTimingOracleTest extends AbstractTestCase
{
    use UsesCodeIgniterModels;

    protected function tearDown(): void
    {
        $this->tearDownCodeIgniter();
        parent::tearDown();
    }

    #[Test]
    public function it_runs_a_bcrypt_comparison_even_when_the_email_does_not_exist(): void
    {
        /* Arrange */
        $ci = $this->bootCodeIgniter();
        require_once APPPATH . 'libraries/Crypt.php';
        $ci->crypt = new Crypt();
        $model = $this->ciModel('sessions/models/Mdl_sessions');

        /* Act */
        $start  = hrtime(true);
        $result = $model->auth(
            'definitely-nobody-' . bin2hex(random_bytes(6)) . '@example.invalid',
            'wrong-password'
        );
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        /* Assert */
        self::assertFalse($result, 'A non-existent email must never authenticate.');
        self::assertGreaterThan(
            5.0,
            $elapsedMs,
            'auth() for a non-existent email must still run a bcrypt comparison against a decoy hash ' .
            'instead of returning immediately: a plain "no row found" return takes microseconds, while a ' .
            'bcrypt (cost 10) comparison takes tens of milliseconds, so a near-instant result here means ' .
            'the timing oracle (CWE-208) is back.'
        );
    }

    #[Test]
    public function it_defines_the_decoy_hash_as_a_real_bcrypt_hash_matching_the_production_cost_factor(): void
    {
        /* Arrange */
        $this->ciModel('sessions/models/Mdl_sessions');
        $ref = new ReflectionClass(Mdl_Sessions::class);

        /* Act */
        $hash = $ref->getConstant('DUMMY_PASSWORD_HASH');

        /* Assert */
        self::assertIsString($hash);
        self::assertStringStartsWith(
            '$2y$10$',
            $hash,
            'The decoy hash must be a real bcrypt hash at the same cost factor (10) as password_hash() ' .
            'uses for real users, or its comparison time will not match the genuine-account path.'
        );
        self::assertFalse(
            password_verify('wrong-password', $hash),
            'Sanity check: the decoy hash must not happen to match the password used by the timing test above.'
        );
    }
}
