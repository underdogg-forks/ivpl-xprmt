<?php

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCiSettings;

class CurrencySymbolSecurityTest extends TestCase
{
    private mixed $previousCi = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousCi          = $GLOBALS['unitCiInstance'] ?? null;
        $GLOBALS['unitCiInstance'] = (object) ['mdl_settings' => new FakeCiSettings()];
        require_once dirname(__DIR__, 3) . '/application/helpers/echo_helper.php';
        require_once dirname(__DIR__, 3) . '/application/helpers/settings_helper.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['unitCiInstance'] = $this->previousCi;

        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_escapes_currency_symbol_in_get_setting(): void
    {
        // When get_setting('currency_symbol') is called without escaping,
        // and the setting contains HTML, it should be escaped by the caller

        $CI                                         = & get_instance();
        $CI->mdl_settings->_data['currency_symbol'] = '<script>alert(1)</script>';

        // get_setting without escape flag returns raw value
        $raw = get_setting('currency_symbol', '', false);
        $this->assertStringContainsString('<script>', $raw);

        // get_setting with escape flag should return escaped
        $escaped = get_setting('currency_symbol', '', true);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
        $this->assertStringNotContainsString('<script>', $escaped);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_escapes_currency_symbol_in_a_real_view_sink(): void
    {
        /*
         * Regression for GHSA-gpv9-p6gj-238h: 15 view sinks echo currency_symbol directly
         * via htmlsc(get_setting('currency_symbol')). The previous version of this test
         * called htmlsc() on a local variable instead of any of those 15 files, so it would
         * stay green even if every one of them were reverted to a raw echo. This renders
         * one of the actual sinks (shared by both the invoice and quote item-discount
         * partials) and asserts on its real output.
         */

        /* Arrange */
        require_once dirname(__DIR__, 3) . '/application/helpers/echo_helper.php';
        require_once dirname(__DIR__, 3) . '/application/helpers/number_helper.php';
        require_once dirname(__DIR__, 3) . '/application/helpers/trans_helper.php';
        $GLOBALS['unitCiInstance']->lang = new class () {
            public function line(string $line): string
            {
                return $line;
            }
        };
        $CI                                         = & get_instance();
        $CI->mdl_settings->_data['currency_symbol'] = '<img src=x onerror=alert(1)>';

        /* Act */
        ob_start();
        include dirname(__DIR__, 3) . '/application/modules/layout/views/partial/itemlist_table_item_discount_input.php';
        $html = (string) ob_get_clean();

        /* Assert */
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_preserves_a_benign_currency_symbol_in_a_real_view_sink(): void
    {
        /* Arrange */
        require_once dirname(__DIR__, 3) . '/application/helpers/echo_helper.php';
        require_once dirname(__DIR__, 3) . '/application/helpers/number_helper.php';
        require_once dirname(__DIR__, 3) . '/application/helpers/trans_helper.php';
        $GLOBALS['unitCiInstance']->lang = new class () {
            public function line(string $line): string
            {
                return $line;
            }
        };
        $CI                                         = & get_instance();
        $CI->mdl_settings->_data['currency_symbol'] = '€';

        /* Act */
        ob_start();
        include dirname(__DIR__, 3) . '/application/modules/layout/views/partial/itemlist_table_item_discount_input.php';
        $html = (string) ob_get_clean();

        /* Assert */
        $this->assertStringContainsString('€', $html);
    }
}
