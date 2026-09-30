<?php

namespace Tests\Unit\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-rg9r-j4c2-8xr6 / GHSA-gpv9-p6gj-238h: after format_currency() was fixed, fifteen view templates
 * still echoed get_setting('currency_symbol') raw, so a stored payload executed in administrators'
 * browsers on the invoice, quote, product and task editors.
 */
class CurrencySymbolSecurityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function hostileSymbols(): array
    {
        return [
            'script tag'         => ['<script>alert(document.cookie)</script>'],
            'img onerror'        => ['<img src=x onerror=alert(1)>'],
            'attribute breakout' => ['" onmouseover="alert(1)'],
            'single quote'       => ["' onmouseover='alert(1)"],
            'svg onload'         => ['<svg onload=alert(1)>'],
            'closing tags'       => ['</span></div><script>alert(1)</script>'],
        ];
    }

    #[Test]
    public function no_view_or_template_echoes_the_currency_symbol_unescaped(): void
    {
        $sinks   = 0;
        $leaks   = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_PATH . '/application', \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            // Only templates render output; controllers merely pass the value along.
            if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/views/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if ( ! str_contains($source, "currency_symbol'")) {
                continue;
            }

            // Only the setting itself (not currency_symbol_placement), read through get_setting().
            preg_match_all('/(htmlsc\(\s*|html_escape\(\s*)?get_setting\(\s*[\'"]currency_symbol[\'"]\s*(,\s*[^,)]*\s*,\s*true\s*)?\)/', $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $sinks++;
                $wrapped = ($match[1] ?? '') !== '' || isset($match[2]);

                if ( ! $wrapped) {
                    $leaks[] = str_replace(ROOT_PATH . '/', '', $file->getPathname()) . ': ' . $match[0];
                }
            }
        }

        self::assertGreaterThanOrEqual(15, $sinks, 'the scan must find the known sinks, otherwise it proves nothing');
        self::assertSame([], $leaks, "get_setting('currency_symbol') must be wrapped in htmlsc() or use the escape flag");
    }

    #[Test]
    #[DataProvider('hostileSymbols')]
    public function it_escapes_the_symbol_in_the_quote_discount_partial(string $hostile): void
    {
        $html = $this->render('application/modules/quotes/views/partial_itemlist_table_quote_discount.php', $hostile, [
            'quote' => ['quote_discount_amount' => '5', 'quote_discount_percent' => '0'],
        ]);

        $this->assertSymbolEscaped($html, $hostile);
    }

    #[Test]
    #[DataProvider('hostileSymbols')]
    public function it_escapes_the_symbol_in_the_invoice_discount_partial(string $hostile): void
    {
        $html = $this->render('application/modules/invoices/views/partial_itemlist_table_invoice_discount.php', $hostile, [
            'invoice' => ['invoice_discount_amount' => '5', 'invoice_discount_percent' => '0', 'is_read_only' => 0],
        ]);

        $this->assertSymbolEscaped($html, $hostile);
    }

    #[Test]
    public function it_still_shows_an_ordinary_symbol_unchanged(): void
    {
        $html = $this->render('application/modules/quotes/views/partial_itemlist_table_quote_discount.php', '€', [
            'quote' => ['quote_discount_amount' => '5', 'quote_discount_percent' => '0'],
        ]);

        self::assertStringContainsString('<span class="input-group-addon">€</span>', $html);
    }

    #[Test]
    public function it_escapes_the_get_setting_escape_flag_variant(): void
    {
        require_once ROOT_PATH . '/application/helpers/echo_helper.php';
        require_once ROOT_PATH . '/application/helpers/settings_helper.php';

        $previous = $GLOBALS['unitCiInstance'] ?? null;
        $GLOBALS['unitCiInstance'] = new class {
            public object $mdl_settings;

            public function __construct()
            {
                $this->mdl_settings = new class {
                    public function setting(string $key, $default = ''): string
                    {
                        return '<script>alert(1)</script>';
                    }
                };
            }
        };

        try {
            self::assertStringContainsString('<script>', get_setting('currency_symbol'), 'without the flag the value is raw (callers must escape)');
            self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', get_setting('currency_symbol', '', true));
        } finally {
            if ($previous === null) {
                unset($GLOBALS['unitCiInstance']);
            } else {
                $GLOBALS['unitCiInstance'] = $previous;
            }
        }
    }

    private function assertSymbolEscaped(string $html, string $hostile): void
    {
        self::assertStringNotContainsString($hostile, $html, 'the raw payload reached the page');
        self::assertStringContainsString('<span class="input-group-addon">' . htmlspecialchars($hostile, ENT_QUOTES, 'UTF-8') . '</span>', $html);
        self::assertSame(0, preg_match('/<script/i', $html), 'a script element must not be injectable');
    }

    /**
     * @param array<string, array<string, mixed>> $vars
     */
    private function render(string $view, string $symbol, array $vars): string
    {
        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(ROOT_PATH . '/tests/Support/render-view.php'),
            escapeshellarg($view),
            escapeshellarg(base64_encode((string) json_encode(['settings' => ['currency_symbol' => $symbol], 'vars' => $vars])))
        );

        $html = (string) shell_exec($command . ' 2>&1');

        self::assertStringContainsString('input-group-addon', $html, 'the view did not render: ' . $html);

        return $html;
    }
}
