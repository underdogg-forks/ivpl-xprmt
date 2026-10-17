<?php

namespace Tests\Feature\Core;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Upstream #1236: the "Show page numbers in PDF footer" select on the invoice settings tab
 * (application/modules/settings/views/partial_settings_invoices.php). The PDF behaviour is covered by
 * Tests\Unit\Helpers\PdfPageNumbersFooterTest; this covers the form control that saves the setting.
 */
#[Group('settings')]
final class SettingsPdfPageNumbersTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->withEnvironment(['SETUP_COMPLETED' => 'true', 'DISABLE_SETUP' => 'true']);
    }

    #[Test]
    public function it_renders_a_labelled_yes_no_select_for_the_page_number_setting(): void
    {
        /* Act */
        $body = $this->get('/settings')->body();

        /* Assert */
        self::assertStringContainsString('name="settings[pdf_page_numbers]"', $body);
        self::assertStringContainsString('Show page numbers in PDF footer', $body);
        self::assertSame(1, preg_match('/<select name="settings\[pdf_page_numbers\]".*?<\/select>/s', $body, $m));
        self::assertStringContainsString('<option value="0"', $m[0]);
        self::assertStringContainsString('<option value="1"', $m[0]);
    }

    #[Test]
    public function it_preselects_no_until_the_setting_is_saved_and_yes_afterwards(): void
    {
        /* Arrange */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'pdf_page_numbers', 'setting_value' => '0']);
        $this->databaseUpdate('ip_settings', ['setting_value' => '0'], ['setting_key' => 'pdf_page_numbers']);

        /* Act */
        $off = $this->selectMarkup($this->get('/settings')->body());
        $this->databaseUpdate('ip_settings', ['setting_value' => '1'], ['setting_key' => 'pdf_page_numbers']);
        $on = $this->selectMarkup($this->get('/settings')->body());

        /* Assert: "yes" is selected only once the setting is 1 */
        self::assertDoesNotMatchRegularExpression('/<option value="1"[^>]*selected/', $off);
        self::assertMatchesRegularExpression('/<option value="1"[^>]*selected/', $on);
    }

    private function selectMarkup(string $body): string
    {
        self::assertSame(1, preg_match('/<select name="settings\[pdf_page_numbers\]".*?<\/select>/s', $body, $m), 'The select is missing from the settings page.');

        return $m[0];
    }
}
