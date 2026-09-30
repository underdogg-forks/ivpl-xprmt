<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-mp5h-3jcr-f7hm: gateway settings were echoed bare into JavaScript string literals on the
 * guest payment pages. These tests render the real view files with hostile values and check what
 * a browser would actually receive, instead of re-testing json_encode() in isolation.
 */
class GatewaySecurityTest extends TestCase
{
    private const VIEW_DIR = __DIR__ . '/../../application/modules/guest/views/gateways/';

    /** @var object|null */
    private $previousCi;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Support/view-render-stubs.php';
        require_once ROOT_PATH . '/application/helpers/echo_helper.php';
        require_once ROOT_PATH . '/application/helpers/settings_helper.php';

        defined('IP_DEBUG') || define('IP_DEBUG', false);

        $this->previousCi = $GLOBALS['unitCiInstance'] ?? null;

        // _core_asset() (called by paypal.php) reads the current_version setting and the real
        // site_url() delegates to the config object.
        $GLOBALS['unitCiInstance'] = new class {
            public object $mdl_settings;

            public object $config;

            public function __construct()
            {
                $this->mdl_settings = new class {
                    public function setting(string $key): string
                    {
                        return '';
                    }
                };

                $this->config = new class {
                    public function site_url($uri = '', $protocol = null): string
                    {
                        return 'http://localhost/index.php/' . (is_array($uri) ? implode('/', $uri) : $uri);
                    }
                };
            }
        };
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
    public static function hostileValues(): array
    {
        return [
            'single-quote breakout'         => ["'-alert('XSS')-'"],
            'string terminator + statement' => ["';alert(1);//"],
            'script tag close and reopen'   => ['</script><script>alert(2)</script>'],
            'html comment opener'           => ['<!--<script>alert(3)</script>'],
            'double quotes'                 => ['"};alert(4);var x={"'],
            'backslash before quote'        => ["\\'; alert(5); //"],
            'newline and line separator'    => ["a\nb\u{2028}c\u{2029}d"],
            'ampersand entity'              => ['&#39;&lt;script&gt;'],
            'empty string'                  => [''],
            'unicode'                       => ['клиент-ID-日本語'],
        ];
    }

    #[Test]
    #[DataProvider('hostileValues')]
    public function it_keeps_every_paypal_setting_inside_a_json_string_literal(string $hostile): void
    {
        $html = $this->render('paypal.php', [
            'advanced_credit_cards' => false,
            'venmo'                 => false,
            'paypal_client_id'      => $hostile,
            'currency'              => $hostile,
            'invoice_url_key'       => $hostile,
        ]);

        foreach (['clientId', 'currency', 'invoiceUrlKey'] as $key) {
            $this->assertSame(
                $hostile,
                $this->jsLiteral($html, $key),
                "PayPalConfig.$key must decode back to the exact stored value, proving it cannot break out of the string"
            );
        }

        $this->assertNoScriptBreakout($html, $hostile);
    }

    #[Test]
    #[DataProvider('hostileValues')]
    public function it_keeps_the_stripe_api_key_inside_a_json_string_literal(string $hostile): void
    {
        $html = $this->render('stripe.php', [
            'stripe_api_key'  => $hostile,
            'invoice_url_key' => 'abc123',
        ]);

        $this->assertMatchesRegularExpression('/Stripe\((".*")\);/', $html);
        preg_match('/Stripe\((".*")\);/', $html, $m);
        $this->assertSame($hostile, json_decode($m[1]));

        $this->assertNoScriptBreakout($html, $hostile);
    }

    #[Test]
    public function it_renders_a_benign_paypal_config_unchanged_in_meaning(): void
    {
        $html = $this->render('paypal.php', [
            'advanced_credit_cards' => true,
            'venmo'                 => true,
            'paypal_client_id'      => 'AbC-123_xyz',
            'currency'              => 'EUR',
            'invoice_url_key'       => 'k3y',
        ]);

        $this->assertSame('AbC-123_xyz', $this->jsLiteral($html, 'clientId'));
        $this->assertSame('EUR', $this->jsLiteral($html, 'currency'));
        $this->assertStringContainsString('advEnabled: true', $html);
        $this->assertStringContainsString('venmoEnabled: true', $html);
    }

    #[Test]
    public function it_hex_encodes_html_metacharacters_so_the_script_block_cannot_be_closed_early(): void
    {
        $html = $this->render('paypal.php', [
            'advanced_credit_cards' => false,
            'venmo'                 => false,
            'paypal_client_id'      => '</script>',
            'currency'              => 'EUR',
            'invoice_url_key'       => 'k',
        ]);

        // JSON_HEX_TAG hex-escapes the angle brackets, so the raw closing tag must not survive.
        $this->assertStringContainsString(chr(92) . 'u003C' . chr(92) . '/script' . chr(92) . 'u003E', $html);

        $benign = $this->render('paypal.php', [
            'advanced_credit_cards' => false,
            'venmo'                 => false,
            'paypal_client_id'      => 'x',
            'currency'              => 'EUR',
            'invoice_url_key'       => 'k',
        ]);

        $this->assertSame(
            substr_count($benign, '</script>'),
            substr_count($html, '</script>'),
            'a hostile value must not add or remove a closing script tag'
        );
    }

    private function assertNoScriptBreakout(string $html, string $hostile): void
    {
        if ($hostile !== '' && preg_match('/[<>"\'\\\\]/', $hostile)) {
            $this->assertStringNotContainsString($hostile, $html, 'the raw hostile value must never appear in the page');
        }

        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertStringNotContainsString('<!--<script>', $html);
    }

    private function jsLiteral(string $html, string $key): mixed
    {
        $this->assertSame(1, preg_match('/' . preg_quote($key, '/') . ': (".*"),\R/', $html, $m), "PayPalConfig.$key must be emitted as a JSON string literal");

        return json_decode($m[1]);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $view, array $vars): string
    {
        $context = new class {
            public object $security;

            public function __construct()
            {
                $this->security = new class {
                    public function get_csrf_token_name(): string
                    {
                        return 'ip_csrf';
                    }

                    public function get_csrf_hash(): string
                    {
                        return 'hash';
                    }
                };
            }
        };

        $render = function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            include $__file;

            return (string) ob_get_clean();
        };

        return $render->call($context, self::VIEW_DIR . $view, $vars);
    }
}
