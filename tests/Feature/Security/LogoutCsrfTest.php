<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * Sessions::logout() destroyed the session on a plain GET with no CSRF
 * check, so an attacker page could force an authenticated victim's session
 * to end simply by getting them to navigate to /sessions/logout (CWE-352).
 *
 * Logout is now a state-changing action like any other in this app: it
 * requires POST and a valid _csrf_field() token, matching the same
 * ensure_valid_post_request()-equivalent pattern Sessions already uses for
 * the new-password POST (Sessions is a Base_Controller, not an
 * Admin_Controller, so it mirrors the check inline rather than calling
 * ensure_valid_post_request()).
 *
 * The functional suite runs with CSRF_PROTECTION=false by default (see
 * .github/workflows/phpunit.yml), under which verify_csrf_token() early-
 * returns true and this gate is never exercised — the exact config-parity
 * trap config-parity-guard checks for. Every test here therefore explicitly
 * enables CSRF_PROTECTION to exercise the production configuration.
 */
final class LogoutCsrfTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->enableCsrfProtection();
    }

    #[Test]
    public function it_does_not_destroy_the_session_for_a_plain_get_request(): void
    {
        /* Arrange: simulating a forced top-level cross-site navigation to the logout link */

        /* Act */
        $response = $this->get('/sessions/logout');

        /* Assert: the session survives -- a GET is never enough to log out */
        self::assertTrue(
            $response->sessionActive(),
            'A bare GET to /sessions/logout must not destroy the session.'
        );
    }

    #[Test]
    public function it_does_not_destroy_the_session_for_a_post_without_a_valid_csrf_token(): void
    {
        /* Arrange: a forged cross-site POST can't read the victim's CSRF cookie */

        /* Act */
        $response = $this->postWithoutCsrfToken('/sessions/logout');

        /* Assert: the session survives -- the request is a no-op, not a forced logout */
        self::assertTrue(
            $response->sessionActive(),
            'A POST to /sessions/logout without a matching CSRF token must not destroy the session.'
        );
    }

    #[Test]
    public function it_destroys_the_session_for_a_post_with_a_valid_csrf_token(): void
    {
        /* Arrange: the real logout form embeds the token via _csrf_field(), matching the CSRF cookie */

        /* Act */
        $response = $this->postWithValidCsrfToken('/sessions/logout');

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'sessions/login');
        self::assertFalse(
            $response->sessionActive(),
            'A genuine logout POST with a valid CSRF token must still destroy the session.'
        );
    }
}
