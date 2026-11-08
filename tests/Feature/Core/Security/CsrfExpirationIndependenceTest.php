<?php

namespace Tests\Feature\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * csrf_expire must not be derived from SESS_EXPIRATION.
 *
 * With SESS_EXPIRATION=0 (session until the browser closes) csrf_expire used to become 0, so the CSRF cookie got
 * Max-Age=0, the browser dropped it at once, and every POST failed with a 403. The request harness cannot read
 * Set-Cookie attributes, so the lifetime is asserted on the booted config; the request tests then prove the
 * token check itself still accepts a valid token and still rejects a missing one under SESS_EXPIRATION=0.
 */
#[Group('security')]
final class CsrfExpirationIndependenceTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    /**
     * @return array<string, array{0: string}>
     */
    public static function sessionExpirations(): array
    {
        return [
            'session until browser closes' => ['0'],
            'short session'                => ['60'],
            'ten day session'              => ['864000'],
        ];
    }

    #[Test]
    #[DataProvider('sessionExpirations')]
    public function it_keeps_the_csrf_lifetime_at_one_hour_whatever_the_session_lifetime_is(string $sessExpiration): void
    {
        /* Arrange */
        $environment = ['SESS_EXPIRATION' => $sessExpiration];

        /* Act */
        $config = $this->bootConfig($environment);

        /* Assert */
        self::assertEquals($sessExpiration, $config['sess_expiration'], 'The probe must actually apply SESS_EXPIRATION.');
        self::assertEquals(3600, $config['csrf_expire']);
    }

    #[Test]
    public function it_lets_csrf_expire_be_overridden_independently_of_the_session(): void
    {
        /* Arrange */
        $environment = ['SESS_EXPIRATION' => '0', 'CSRF_EXPIRE' => '120'];

        /* Act */
        $config = $this->bootConfig($environment);

        /* Assert */
        self::assertEquals(120, $config['csrf_expire']);
        self::assertEquals(0, $config['sess_expiration']);
    }

    #[Test]
    public function it_accepts_a_post_with_a_valid_csrf_token_when_the_session_never_expires(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $this->withEnvironment(['SESS_EXPIRATION' => '0']);
        $this->actingAsAdmin();

        /* Act */
        $response = $this->postWithValidCsrfToken('/settings');

        /* Assert */
        self::assertNotSame(403, $response->statusCode(), 'A valid token must pass the CSRF check when SESS_EXPIRATION=0.');
    }

    #[Test]
    public function it_rejects_a_post_without_a_csrf_token_when_the_session_never_expires(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $this->withEnvironment(['SESS_EXPIRATION' => '0']);
        $this->actingAsAdmin();

        /* Act */
        $response = $this->postWithoutCsrfToken('/settings');

        /* Assert */
        self::assertSame(403, $response->statusCode());
    }

    /**
     * Loads config.php without ipconfig.php: that file pins CSRF_EXPIRE, which would hide the default the bug lived in.
     * env() is defined as bootstrap/kernel.php defines it (the $_ENV array only).
     *
     * @param array<string, string> $env
     *
     * @return array<string, mixed>
     */
    private function bootConfig(array $env): array
    {
        $code = 'foreach (' . var_export($env, true) . ' as $k => $v) { $_ENV[$k] = $v; }'
            . "define('BASEPATH', '/'); define('IP_DEBUG', false);"
            . 'function env(string $key, mixed $default = null): mixed { return $_ENV[$key] ?? $default; }'
            . 'function env_bool(string $key, bool $default = false): bool { return isset($_ENV[$key]) ? filter_var($_ENV[$key], FILTER_VALIDATE_BOOLEAN) : $default; }'
            . 'require ' . var_export(ROOT_PATH . '/bootstrap/session_path.php', true) . ';'
            . '$config = [];'
            . 'require ' . var_export(ROOT_PATH . '/application/config/config.php', true) . ';'
            . 'echo json_encode($config);';

        $output = [];
        $status = 1;
        exec(PHP_BINARY . ' -r ' . escapeshellarg($code), $output, $status);

        self::assertSame(0, $status, 'config boot probe must exit cleanly.');

        return json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
    }
}
