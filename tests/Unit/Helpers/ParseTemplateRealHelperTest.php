<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-hjm5-8m94-rq9w: email templates substitute {{{placeholder}}} values from the invoice/quote row,
 * which is joined with ip_users and ip_clients, so a template author could print {{{user_password}}}
 * or {{{user_passwordreset_token}}}. The fix is an allowlist in parse_template(). The unit bootstrap
 * defines a stub parse_template() that returns the template unchanged (tests/Support/UnitCodeIgniter.php),
 * so no other test ever executes the real function. This runs the real helper in a clean PHP process.
 */
final class ParseTemplateRealHelperTest extends TestCase
{
    #[Test]
    public function it_substitutes_allowed_placeholders(): void
    {
        /* Act */
        $output = $this->runParseTemplate(['client_name' => 'Alice Example'], 'Dear {{{client_name}}},');

        /* Assert */
        self::assertSame('Dear Alice Example,', $output);
    }

    #[Test]
    public function it_never_prints_the_password_hash_or_reset_token_columns(): void
    {
        /* Arrange: the row really carries the secrets, as the joined invoice query would */
        $row = [
            'client_name'              => 'Alice Example',
            'user_password'            => '$2y$10$SECRETHASHVALUE',
            'user_psalt'               => 'SECRETSALT',
            'user_passwordreset_token' => 'SECRETRESETTOKEN',
        ];

        /* Act */
        $output = $this->runParseTemplate($row, '{{{client_name}}}|{{{user_password}}}|{{{user_psalt}}}|{{{user_passwordreset_token}}}');

        /* Assert: allowed value substituted, non-allowlisted placeholders left unreplaced */
        self::assertStringContainsString('Alice Example', $output);
        self::assertStringNotContainsString('SECRETHASHVALUE', $output);
        self::assertStringNotContainsString('SECRETSALT', $output);
        self::assertStringNotContainsString('SECRETRESETTOKEN', $output);
    }

    #[Test]
    public function it_escapes_substituted_values_only_when_asked(): void
    {
        /* Act */
        $raw     = $this->runParseTemplate(['client_name' => '<b>x</b>'], '{{{client_name}}}');
        $escaped = $this->runParseTemplate(['client_name' => '<b>x</b>'], '{{{client_name}}}', true);

        /* Assert */
        self::assertSame('<b>x</b>', $raw);
        self::assertSame('&lt;b&gt;x&lt;/b&gt;', $escaped);
    }

    #[Test]
    public function it_returns_null_and_empty_templates_unchanged(): void
    {
        /* Act + Assert */
        self::assertSame('NULL', $this->runParseTemplate([], null, false, true));
        self::assertSame('', $this->runParseTemplate([], ''));
    }

    private function runParseTemplate(array $row, ?string $body, bool $escape = false, bool $exportNull = false): string
    {
        $script = sprintf(
            "define('BASEPATH', '/'); require %s; \$r = parse_template((object) json_decode(%s, true), %s, %s); echo %s;",
            var_export(dirname(__DIR__, 3) . '/application/helpers/template_helper.php', true),
            var_export(json_encode($row), true),
            var_export($body, true),
            var_export($escape, true),
            $exportNull ? "\$r === null ? 'NULL' : \$r" : '(string) $r'
        );

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($script) . ' 2>&1');
        return (string) $output;
    }
}
