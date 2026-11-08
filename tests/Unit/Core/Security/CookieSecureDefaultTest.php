<?php

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CWE-614 / CWE-1188: config.php reads env_bool('COOKIE_SECURE', true), env_bool('SESS_MATCH_IP', true) and
 * env_bool('SESS_REGENERATE_DESTROY', true). The index.php helper of 1.7.2 compared the raw value with the string
 * 'true', so the boolean default true never matched: on every install whose ipconfig.php lacks the key (COOKIE_SECURE
 * is not in the shipped template) the session and CSRF cookies were issued without the Secure attribute, and the two
 * session settings were off on installs that predate them. Each probe runs in its own PHP process with an empty
 * environment, so a key that is "absent" really is absent.
 */
#[Group('security')]
final class CookieSecureDefaultTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function defaultOnSettings(): array
    {
        return [
            'cookie_secure'           => ['cookie_secure'],
            'sess_match_ip'           => ['sess_match_ip'],
            'sess_regenerate_destroy' => ['sess_regenerate_destroy'],
        ];
    }

    /**
     * @return array<string, array{string, string}> the ipconfig key, and the config item it drives
     */
    public static function keysAndItems(): array
    {
        return [
            'COOKIE_SECURE'           => ['COOKIE_SECURE', 'cookie_secure'],
            'SESS_MATCH_IP'           => ['SESS_MATCH_IP', 'sess_match_ip'],
            'SESS_REGENERATE_DESTROY' => ['SESS_REGENERATE_DESTROY', 'sess_regenerate_destroy'],
        ];
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function writtenValues(): array
    {
        return [
            'true'    => ['true', true],
            'TRUE'    => ['TRUE', true],
            '1'       => ['1', true],
            'false'   => ['false', false],
            'FALSE'   => ['FALSE', false],
            '0'       => ['0', false],
            'garbage' => ['banana', false],
        ];
    }

    #[Test]
    #[DataProvider('defaultOnSettings')]
    public function it_turns_each_hardening_setting_on_when_its_key_is_absent(string $setting): void
    {
        /* Arrange: no ipconfig.php keys at all */
        $config = $this->bootConfig([]);

        /* Act */
        $value = $config[$setting] ?? null;

        /* Assert: strictly true, not merely truthy */
        self::assertTrue($value, $setting . ' must default to true.');
    }

    #[Test]
    #[DataProvider('keysAndItems')]
    public function it_still_lets_an_admin_turn_each_setting_off(string $key, string $item): void
    {
        /* Arrange: a plain-HTTP install sets COOKIE_SECURE=false; the default must not override that */
        $config = $this->bootConfig([$key => 'false']);

        /* Act */
        $value = $config[$item] ?? null;

        /* Assert */
        self::assertFalse($value, $key . '=false must win over the default.');
    }

    #[Test]
    #[DataProvider('writtenValues')]
    public function it_reads_a_written_value_the_way_an_admin_means_it(string $written, bool $expected): void
    {
        /* Arrange */
        $config = $this->bootConfig(['COOKIE_SECURE' => $written]);

        /* Act */
        $value = $config['cookie_secure'] ?? null;

        /* Assert */
        self::assertSame($expected, $value);
    }

    #[Test]
    public function it_returns_a_boolean_default_for_an_absent_key_whichever_way_it_is_given(): void
    {
        /* Arrange + Act */
        $withTrue  = $this->probe('echo json_encode(env_bool("NOT_SET_ANYWHERE", true));', []);
        $withFalse = $this->probe('echo json_encode(env_bool("NOT_SET_ANYWHERE", false));', []);

        /* Assert: a default of true is the case that used to come out false */
        self::assertSame('true', $withTrue);
        self::assertSame('false', $withFalse);
    }

    #[Test]
    public function it_documents_cookie_secure_as_an_active_line_in_the_shipped_template(): void
    {
        /* Arrange */
        $template = (string) file_get_contents(ROOT_PATH . '/ipconfig.php.example');

        /* Assert: an active line, so a copy of the template is secure by explicit choice as well as by default,
           and the comment tells a plain-HTTP install what to do */
        self::assertMatchesRegularExpression('/^COOKIE_SECURE=true$/m', $template);
        self::assertMatchesRegularExpression('/plain.HTTP/i', $template);
    }

    #[Test]
    public function it_documents_every_setting_that_defaults_to_true_in_the_shipped_template(): void
    {
        /* Arrange: every key config.php reads with a default of true */
        $config = (string) file_get_contents(ROOT_PATH . '/application/config/config.php');
        preg_match_all("/env_bool\\('([A-Z_]+)',\\s*true\\)/", $config, $matches);
        $template = (string) file_get_contents(ROOT_PATH . '/ipconfig.php.example');

        /* Act */
        $undocumented = array_values(array_filter(
            array_unique($matches[1]),
            static fn (string $key): bool => preg_match('/^#?\s*' . $key . '=/m', $template) !== 1
        ));

        /* Assert: an admin reading the template can see, and change, each default that is on */
        self::assertNotEmpty($matches[1], 'the premise: config.php has settings that default to true');
        self::assertSame([], $undocumented, 'These default-on keys are missing from ipconfig.php.example.');
    }

    /**
     * @param array<string, string> $env
     *
     * @return array<string, mixed>
     */
    private function bootConfig(array $env): array
    {
        $code = 'define("BASEPATH", "x"); define("IP_DEBUG", false);'
            . 'require ' . var_export(ROOT_PATH . '/bootstrap/helpers.php', true) . ';'
            . 'require ' . var_export(ROOT_PATH . '/bootstrap/session_path.php', true) . ';'
            . '$config = [];'
            . 'require ' . var_export(ROOT_PATH . '/application/config/config.php', true) . ';'
            . 'echo json_encode($config);';

        return json_decode($this->probe($code, $env), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Runs PHP code in a fresh process whose $_ENV holds only the given keys, with the repository helpers loaded.
     *
     * @param array<string, string> $env
     */
    private function probe(string $code, array $env): string
    {
        $prelude = '$_ENV = ' . var_export($env, true) . ';'
            . 'require_once ' . var_export(ROOT_PATH . '/bootstrap/helpers.php', true) . ';';

        $output = [];
        $status = 1;
        exec('env -i ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($prelude . $code) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, "the probe must exit cleanly:\n" . implode("\n", $output));

        return implode("\n", $output);
    }
}
