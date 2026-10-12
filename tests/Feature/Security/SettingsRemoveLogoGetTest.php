<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * GHSA-qx8h-35wf-cgqf: removing a logo was a state-changing GET link (settings/remove_logo/<type>),
 * so a forged image or link on another site could delete it (CSRF). It must now be a POST. The existing
 * test only covers a POST with a missing token, and a source-level check only looks for ensure_valid_post_request;
 * neither sends the GET that the advisory describes.
 */
final class SettingsRemoveLogoGetTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfProtection();
    }

    #[Test]
    public function it_does_not_clear_the_logo_setting_on_a_get_request(): void
    {
        /* Arrange: a stale logo setting (file absent), which the POST path would happily clear */
        $this->actingAsAdmin();
        $this->databaseDelete('ip_settings', ['setting_key' => 'invoice_logo']);
        $this->databaseInsert('ip_settings', ['setting_key' => 'invoice_logo', 'setting_value' => 'removed-by-get-probe.png']);

        /* Act */
        $response = $this->get('/settings/remove_logo/invoice');

        /* Assert: redirected away and the setting survived */
        self::assertTrue($response->isRedirect() || $response->statusCode() === 404, 'A GET must be refused, not served. Got ' . $response->statusCode());
        $this->assertDatabaseHas('ip_settings', ['setting_key' => 'invoice_logo', 'setting_value' => 'removed-by-get-probe.png']);
    }

    #[Test]
    public function it_clears_a_stale_logo_setting_on_a_valid_post_request(): void
    {
        /* Arrange: same stale setting, as the positive control */
        $this->actingAsAdmin();
        $this->databaseDelete('ip_settings', ['setting_key' => 'invoice_logo']);
        $this->databaseInsert('ip_settings', ['setting_key' => 'invoice_logo', 'setting_value' => 'removed-by-post-probe.png']);

        /* Act */
        $response = $this->postWithValidCsrfToken('/settings/remove_logo/invoice');

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_settings', ['setting_key' => 'invoice_logo', 'setting_value' => '']);
    }
}
