<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-x9qq-6v8r-pfcf: the thousands_separator / decimal_point settings were embedded raw in the
 * output of format_currency(), format_amount() and format_quantity(), which is echoed into the HTML
 * that mPDF renders. mPDF fetches remote resources referenced there, so a stored
 * <img src="http://internal/..."> became a server-side request on every PDF.
 */
class NumberFormattingSecurityTest extends TestCase
{
    private const SSRF_IMG = '<img src="http://169.254.169.254/latest/meta-data/">';

    /** @var object|null */
    private $previousCi;

    protected function setUp(): void
    {
        require_once ROOT_PATH . '/application/helpers/echo_helper.php';
        require_once ROOT_PATH . '/application/helpers/number_helper.php';

        $this->previousCi = $GLOBALS['unitCiInstance'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousCi === null) {
            unset($GLOBALS['unitCiInstance']);
        } else {
            $GLOBALS['unitCiInstance'] = $this->previousCi;
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileSeparators(): array
    {
        return [
            'ssrf img tag'            => [self::SSRF_IMG],
            'script tag'              => ['<script>alert(1)</script>'],
            'attribute breakout'      => ['" onerror="alert(1)'],
            'single quote breakout'   => ["' onerror='alert(1)"],
            'ampersand entity smuggling' => ['&lt;img src=x&gt;'],
            'css url'                 => ['<div style="background:url(http://attacker.example/x)">'],
            'svg with xlink'          => ['<svg><image xlink:href="http://attacker.example/x"/></svg>'],
        ];
    }

    #[Test]
    #[DataProvider('hostileSeparators')]
    public function it_never_emits_markup_from_the_thousands_separator(string $hostile): void
    {
        $this->useSettings(['thousands_separator' => $hostile, 'decimal_point' => '.']);

        foreach (['format_currency' => format_currency(1234567.5), 'format_amount' => format_amount(1234567.5), 'format_quantity' => format_quantity(1234567.5)] as $function => $output) {
            $this->assertNoRawMarkup($output, $function . ' with a hostile thousands_separator');
            self::assertStringContainsString(htmlsc($hostile), $output, $function . ' must still show the (escaped) separator');
        }
    }

    #[Test]
    #[DataProvider('hostileSeparators')]
    public function it_never_emits_markup_from_the_decimal_point(string $hostile): void
    {
        $this->useSettings(['thousands_separator' => ',', 'decimal_point' => $hostile]);

        foreach (['format_currency' => format_currency(12.5), 'format_amount' => format_amount(12.5), 'format_quantity' => format_quantity(12.5)] as $function => $output) {
            $this->assertNoRawMarkup($output, $function . ' with a hostile decimal_point');
            self::assertStringContainsString(htmlsc($hostile), $output, $function . ' must still show the (escaped) decimal point');
        }
    }

    #[Test]
    public function it_escapes_both_separators_at_once(): void
    {
        $this->useSettings(['thousands_separator' => '<b>', 'decimal_point' => '<i>']);

        $output = format_amount(1234.5);

        self::assertSame('1&lt;b&gt;234&lt;i&gt;50', $output);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function realisticSeparators(): array
    {
        return [
            'us style'        => [',', '.', '1,234,567.50', '1,234.50'],
            'european style'  => ['.', ',', '1.234.567,50', '1.234,50'],
            'space + comma'   => [' ', ',', '1 234 567,50', '1 234,50'],
            'swiss apostrophe' => ["'", '.', '1&#039;234&#039;567.50', '1&#039;234.50'],
            'no thousands'    => ['', '.', '1234567.50', '1234.50'],
        ];
    }

    #[Test]
    #[DataProvider('realisticSeparators')]
    public function it_still_formats_numbers_correctly_for_legitimate_separators(string $thousands, string $decimal, string $expectedBig, string $expectedSmall): void
    {
        $this->useSettings(['thousands_separator' => $thousands, 'decimal_point' => $decimal]);

        self::assertSame($expectedBig, format_amount(1234567.5));
        self::assertSame($expectedSmall, format_amount(1234.5));
        self::assertSame($expectedBig, format_quantity(1234567.5));
        self::assertSame($expectedBig . '$', format_currency(1234567.5));
    }

    #[Test]
    public function it_keeps_the_currency_symbol_escaped_alongside_the_separators(): void
    {
        $this->useSettings(['currency_symbol' => '<script>x</script>', 'currency_symbol_placement' => 'before']);

        $output = format_currency(10);

        self::assertStringStartsWith('&lt;script&gt;x&lt;/script&gt;', $output);
        $this->assertNoRawMarkup($output, 'format_currency');
    }

    #[Test]
    public function it_returns_nothing_for_a_zero_amount_without_touching_the_settings(): void
    {
        $this->useSettings(['thousands_separator' => self::SSRF_IMG]);

        self::assertNull(format_amount(0));
        self::assertNull(format_quantity(null));
    }

    private function assertNoRawMarkup(string $output, string $context): void
    {
        self::assertDoesNotMatchRegularExpression('/[<>"]/', $output, $context . ' leaked unescaped markup: ' . $output);
        self::assertStringNotContainsString('169.254.169.254" ', $output);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function useSettings(array $overrides): void
    {
        $settings = array_merge([
            'currency_symbol'           => '$',
            'currency_symbol_placement' => 'after',
            'thousands_separator'       => ',',
            'decimal_point'             => '.',
            'tax_rate_decimal_places'   => '2',
            'default_item_decimals'     => '2',
        ], $overrides);

        $GLOBALS['unitCiInstance'] = new class ($settings) {
            public object $mdl_settings;

            public function __construct(array $settings)
            {
                $this->mdl_settings = new class ($settings) {
                    public function __construct(private array $settings) {}

                    public function setting(string $key): string
                    {
                        return (string) ($this->settings[$key] ?? '');
                    }
                };
            }
        };
    }
}
