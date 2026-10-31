<?php

namespace Tests\Feature\Core\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * The CSRF cookie lifetime (csrf_expire) must not follow the session lifetime (SESS_EXPIRATION).
 * With SESS_EXPIRATION=0 (session until the browser closes) csrf_expire used to become 0, the CSRF
 * cookie got Max-Age=0 and was dropped at once, so every POST failed with a 403.
 *
 * headers_list() is empty under the CLI SAPI, so the cookie's Max-Age cannot be read from a response.
 * The first group of tests therefore reads the booted config, the second proves a POST still passes
 * (and an unprotected one is still refused) at SESS_EXPIRATION=0.
 */
#[Group('security')]
final class CsrfExpirationIndependenceTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    #[Test]
    public function it_keeps_the_csrf_expiry_at_one_hour_when_sess_expiration_is_zero(): void
    {
        /* Arrange */
        $env = ['SESS_EXPIRATION' => '0'];

        /* Act */
        $config = $this->bootConfig($env);

        /* Assert */
        self::assertSame(3600, (int) $config['csrf_expire'], 'csrf_expire must not follow SESS_EXPIRATION=0.');
        self::assertSame(0, (int) $config['sess_expiration'], 'Positive control: the probe really applied SESS_EXPIRATION=0.');
    }

    #[Test]
    public function it_keeps_the_csrf_expiry_at_one_hour_for_a_normal_session_lifetime(): void
    {
        /* Arrange: positive control, the default shape of the same probe */
        $env = ['SESS_EXPIRATION' => '864000'];

        /* Act */
        $config = $this->bootConfig($env);

        /* Assert */
        self::assertSame(3600, (int) $config['csrf_expire']);
        self::assertSame(864000, (int) $config['sess_expiration']);
    }

    #[Test]
    public function it_lets_csrf_expire_be_set_on_its_own(): void
    {
        /* Arrange */
        $env = ['SESS_EXPIRATION' => '0', 'CSRF_EXPIRE' => '120'];

        /* Act */
        $config = $this->bootConfig($env);

        /* Assert */
        self::assertSame(120, (int) $config['csrf_expire']);
    }

    #[Test]
    public function it_renders_the_login_form_with_a_csrf_token_when_sess_expiration_is_zero(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $this->withEnvironment(['SESS_EXPIRATION' => '0']);

        /* Act */
        $response = $this->get('/sessions/login');

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertMatchesRegularExpression('/<input[^>]*name="_ip_csrf"[^>]*value="[^"]+"/', $response->body(), 'The form must carry a CSRF token.');
    }

    #[Test]
    public function it_accepts_a_post_with_a_valid_csrf_token_when_sess_expiration_is_zero(): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        $this->enableCsrfProtection();
        $this->withEnvironment(['SESS_EXPIRATION' => '0']);

        /* Act */
        $response = $this->postWithValidCsrfToken('/settings');

        /* Assert: not the 403 of a refused token */
        self::assertNotSame(403, $response->statusCode(), 'A valid CSRF token must pass at SESS_EXPIRATION=0.');
        self::assertTrue($response->isRedirect() || $response->isSuccessful(), 'Unexpected status ' . $response->statusCode());
    }

    #[Test]
    public function it_rejects_a_post_without_a_csrf_token_when_sess_expiration_is_zero(): void
    {
        /* Arrange */
        $this->actingAsAdmin();
        $this->enableCsrfProtection();
        $this->withEnvironment(['SESS_EXPIRATION' => '0']);

        /* Act */
        $response = $this->postWithoutCsrfToken('/settings');

        /* Assert */
        self::assertSame(403, $response->statusCode(), 'A POST without a CSRF token must be refused regardless of SESS_EXPIRATION.');
    }

    /**
     * @param array<string, string> $env
     *
     * @return array<string, mixed>
     */
    private function bootConfig(array $env): array
    {
        $code = 'foreach (' . var_export($env, true) . ' as $k => $v) { $_ENV[$k] = $v; }'
            . 'require ' . var_export(ROOT_PATH . '/bootstrap/kernel.php', true) . ';'
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
