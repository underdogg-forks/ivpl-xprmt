<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Sessions::logout() destroyed the session on a plain GET with no CSRF
 * check. Because the session cookie is issued SameSite=Lax (see
 * resources/docker/nginx/invoiceplane.conf), it rides along on a top-level
 * cross-site navigation (a link click, window.location, a redirect), so an
 * attacker page could force an authenticated victim's session to end
 * (CWE-352 / forced logout).
 *
 * The functional suite runs with CSRF_PROTECTION=false by default (see
 * .github/workflows/phpunit.yml), under which verify_get_csrf_token()
 * early-returns true and this gate is never exercised — the exact
 * config-parity trap config-parity-guard checks for. Every test here
 * therefore explicitly enables CSRF_PROTECTION to exercise the production
 * configuration.
 */
final class LogoutCsrfTest extends AbstractTestCase
{
    private const TOKEN = 'logout-csrf-token-0123456789-abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->withEnvironment(['CSRF_PROTECTION' => 'true']);
    }

    /** @return array<string, array{array<string, mixed>, array<string, string>}> */
    public static function rejectedTokens(): array
    {
        return [
            'no token and no cookie' => [[], []],
            'cookie without token'   => [[], ['ip_csrf_cookie' => self::TOKEN]],
            'token without cookie'   => [['_ip_csrf' => self::TOKEN], []],
            'mismatching token'      => [['_ip_csrf' => 'forged-token-0123456789-abcdefghi'], ['ip_csrf_cookie' => self::TOKEN]],
            'empty token and cookie' => [['_ip_csrf' => ''], ['ip_csrf_cookie' => '']],
        ];
    }

    #[Test]
    #[DataProvider('rejectedTokens')]
    public function it_does_not_destroy_the_session_for_a_logout_missing_a_valid_csrf_token(array $query, array $cookies): void
    {
        /* Arrange: an authenticated session, simulating a forced top-level cross-site navigation to the logout link */

        /* Act */
        $response = $this->get('/sessions/logout', $query, $cookies);

        /* Assert: the session survives -- the request is a no-op, not a forced logout */
        self::assertTrue(
            $response->sessionActive(),
            'A logout request with no matching CSRF token must not destroy the session.'
        );
    }

    #[Test]
    public function it_destroys_the_session_for_a_genuine_logout_with_a_valid_csrf_token(): void
    {
        /* Arrange: a same-origin link embeds the token via _csrf_query(), matching the CSRF cookie */

        /* Act */
        $response = $this->get('/sessions/logout', ['_ip_csrf' => self::TOKEN], ['ip_csrf_cookie' => self::TOKEN]);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertFalse(
            $response->sessionActive(),
            'A logout request with a valid CSRF token must still destroy the session.'
        );
    }
}
