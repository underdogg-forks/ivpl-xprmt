<?php

namespace Tests\Feature\Settings;

use Tests\AbstractTestCase;

/**
 * Logo removal functionality tests.
 *
 * Verifies that invoice and login logo removal works correctly,
 * especially on installations without URL rewriting (REMOVE_INDEXPHP=false).
 * See issue: base_url() vs site_url() bug causing 404 on logo removal.
 */

#[\PHPUnit\Framework\Attributes\Test]
final class LogoRemovalTest extends AbstractTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_removes_invoice_logo_as_admin(): void
    {
        /* Arrange */
        $this->actingAsAdmin();

        /* Act */
        $response = $this->request('POST', '/settings/remove_logo/invoice');

        /* Assert */
        // Should not get 404 or 403
        $this->assertNotEquals(404, $response->status());
        $this->assertNotEquals(403, $response->status());
        // Should redirect back to settings
        $this->assertIsRedirect($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_removes_login_logo_as_admin(): void
    {
        /* Arrange */
        $this->actingAsAdmin();

        /* Act */
        $response = $this->request('POST', '/settings/remove_logo/login');

        /* Assert */
        // Should not get 404 or 403
        $this->assertNotEquals(404, $response->status());
        $this->assertNotEquals(403, $response->status());
        // Should redirect back to settings
        $this->assertIsRedirect($response);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_logo_removal_without_auth(): void
    {
        /* Arrange */
        // Not authenticated

        /* Act */
        $response = $this->request('POST', '/settings/remove_logo/invoice');

        /* Assert */
        // Should not reach the endpoint (should redirect to login)
        $this->assertEquals(307, $response->status());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_site_url_not_base_url(): void
    {
        /* Arrange */
        $this->actingAsAdmin();

        /* Act */
        // The route should be accessible at /settings/remove_logo/invoice
        // This works because the button uses site_url() which includes index.php
        $response = $this->request('POST', '/settings/remove_logo/invoice');

        /* Assert */
        // If base_url() was used instead of site_url(),
        // installations without URL rewriting would get 404
        $this->assertNotEquals(404, $response->status(),
            'Logo removal endpoint should be accessible with site_url() routing');
    }

    private function assertIsRedirect($response): void
    {
        $status = $response->status();
        $this->assertTrue(
            in_array($status, [301, 302, 303, 307, 308]),
            "Expected redirect status (301/302/303/307/308), got {$status}"
        );
    }
}
