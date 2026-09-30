<?php

namespace Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Clickjacking / framing protection. The kernel sends these headers only outside the CLI, and the
 * request harness runs in the CLI, so the header *logic* lives in ip_security_headers() and is
 * tested directly; the wiring and the web-server configs are checked against the real files.
 */
class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT_PATH . '/bootstrap/security_headers.php';
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function frameOptionInputs(): array
    {
        return [
            'lowercase deny'           => ['deny', 'DENY'],
            'padded deny'              => ["  Deny \t", 'DENY'],
            'lowercase sameorigin'     => ['sameorigin', 'SAMEORIGIN'],
            'allowall is not a value'  => ['ALLOWALL', 'SAMEORIGIN'],
            'allow-from is obsolete'   => ['ALLOW-FROM https://evil.example', 'SAMEORIGIN'],
            'empty string'             => ['', 'SAMEORIGIN'],
            'whitespace only'          => ['   ', 'SAMEORIGIN'],
            'header injection attempt' => ["DENY\r\nSet-Cookie: pwned=1", 'SAMEORIGIN'],
            'csp injection attempt'    => ['DENY; frame-ancestors *', 'SAMEORIGIN'],
        ];
    }

    #[Test]
    public function it_defaults_to_sameorigin_framing_with_a_matching_csp(): void
    {
        $headers = ip_security_headers();

        self::assertContains('X-Frame-Options: SAMEORIGIN', $headers);
        self::assertContains("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'", $headers);
        self::assertContains('Referrer-Policy: strict-origin-when-cross-origin', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
    }

    #[Test]
    public function it_denies_all_framing_when_configured_to_deny(): void
    {
        $headers = ip_security_headers('DENY');

        self::assertContains('X-Frame-Options: DENY', $headers);
        self::assertContains("Content-Security-Policy: frame-ancestors 'none'; object-src 'none'; base-uri 'self'", $headers);
    }

    #[Test]
    #[DataProvider('frameOptionInputs')]
    public function it_normalises_or_rejects_the_configured_frame_option(string $input, string $expected): void
    {
        $headers = ip_security_headers($input);

        self::assertContains('X-Frame-Options: ' . $expected, $headers);

        foreach ($headers as $line) {
            self::assertDoesNotMatchRegularExpression('/[\r\n]/', $line, 'a header line must never contain a line break');
            self::assertStringNotContainsString('Set-Cookie', $line);
        }

        self::assertCount(1, array_filter($headers, static fn (string $h): bool => str_starts_with($h, 'X-Frame-Options:')));
    }

    #[Test]
    public function it_can_omit_nosniff_but_never_the_framing_headers(): void
    {
        $headers = ip_security_headers('SAMEORIGIN', false);

        self::assertNotContains('X-Content-Type-Options: nosniff', $headers);
        self::assertContains('X-Frame-Options: SAMEORIGIN', $headers);
    }

    #[Test]
    public function the_kernel_sends_the_headers_for_every_web_request_before_dispatching(): void
    {
        $kernel = (string) file_get_contents(ROOT_PATH . '/bootstrap/kernel.php');

        $guard = strpos($kernel, "PHP_SAPI !== 'cli'");
        $call  = strpos($kernel, 'ip_security_headers(');

        self::assertNotFalse($call, 'kernel.php must call ip_security_headers()');
        self::assertNotFalse($guard);
        self::assertGreaterThan($guard, $call, 'the call must sit inside the non-CLI guard');
        self::assertStringContainsString("env('X_FRAME_OPTIONS'", $kernel);
        self::assertStringNotContainsString("header('X-Frame-Options", $kernel, 'the headers must come from the tested function, not a second copy');
    }

    #[Test]
    public function the_nginx_config_does_not_relax_session_cookies_to_samesite_none(): void
    {
        $conf = (string) file_get_contents(ROOT_PATH . '/resources/docker/nginx/invoiceplane.conf');

        self::assertStringContainsString('proxy_cookie_path', $conf);
        self::assertDoesNotMatchRegularExpression('/SameSite\s*=\s*none/i', $conf, 'SameSite=none lets a framing page ride the session cookie');
        self::assertMatchesRegularExpression('/SameSite\s*=\s*(Strict|Lax)/i', $conf);
        self::assertStringContainsString('HTTPOnly', $conf);
        self::assertStringContainsString('Secure', $conf);
    }

    #[Test]
    public function the_apache_config_also_sends_the_framing_headers(): void
    {
        $conf = (string) file_get_contents(ROOT_PATH . '/resources/docker/apache/invoiceplane.conf');

        self::assertMatchesRegularExpression('/Header always set X-Frame-Options "(SAMEORIGIN|DENY)"/', $conf);
        self::assertStringContainsString("frame-ancestors 'self'", $conf);
    }
}
