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
 * was not in the shipped template) the session and CSRF cookies were issued without the Secure attribute, and the two
 * session settings were off on installs that predate them. Each probe runs in its own PHP process whose $_ENV holds
 * only the keys the test gives it, so a key that is "absent" really is absent.
 */
#[Group('security')]
final class CookieSecureDefaultTest extends TestCase
{
    /**
     * @return array<string, array{string}> the config items whose default is on
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
     * @return array<string, array{string, bool}> a written value, and what COOKIE_SECURE (default on) becomes
     */
    public static function writtenValues(): array
    {
        return [
            'true'                => ['true', true],
            'TRUE'                => ['TRUE', true],
            '1'                   => ['1', true],
            'yes'                 => ['yes', true],
            'on'                  => ['on', true],
            'false'               => ['false', false],
            'FALSE'               => ['FALSE', false],
            '0'                   => ['0', false],
            'no'                  => ['no', false],
            'off'                 => ['off', false],
            'typo keeps default'  => ['banana', true],
            'empty keeps default' => ['', true],
            'spaces keep default' => ['   ', true],
        ];
    }

    /**
     * Each hardening setting is strictly true when its ipconfig.php key is missing.
     */
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

    /**
     * An explicit false still wins over the default, so a plain-HTTP install can turn the setting off.
     */
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

    /**
     * A written value is read the way an admin means it, and one that cannot be read keeps the secure default
     * instead of silently switching the protection off.
     */
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

    /**
     * A flag whose default is off is not switched on by a value that cannot be read.
     */
    #[Test]
    public function it_keeps_a_default_off_flag_off_when_its_value_cannot_be_read(): void
    {
        /* Arrange + Act */
        $typo  = $this->probe('echo json_encode(env_bool("A_FLAG", false));', ['A_FLAG' => 'banana']);
        $empty = $this->probe('echo json_encode(env_bool("A_FLAG", false));', ['A_FLAG' => '']);
        $yes   = $this->probe('echo json_encode(env_bool("A_FLAG", false));', ['A_FLAG' => 'true']);

        /* Assert */
        self::assertSame('false', $typo);
        self::assertSame('false', $empty);
        self::assertSame('true', $yes, 'the positive control: a readable true still switches it on');
    }

    /**
     * A boolean default for an absent key comes back as given, whichever way it is passed.
     */
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

    /**
     * The shipped template carries COOKIE_SECURE as an active line, with a note for plain-HTTP installs.
     */
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

    /**
     * Every setting config.php defaults to on, written as true or as the string 'true', is in the template.
     */
    #[Test]
    public function it_documents_every_setting_that_defaults_to_true_in_the_shipped_template(): void
    {
        /* Arrange: every key config.php reads with a default of true, bare or quoted */
        $config = (string) file_get_contents(ROOT_PATH . '/application/config/config.php');
        preg_match_all("/env_bool\\(\\s*'([A-Z_]+)'\\s*,\\s*(?:true|'true'|\"true\")\\s*\\)/i", $config, $matches);
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
     * The sweep above also sees a default written as the string 'true'.
     */
    #[Test]
    public function it_finds_a_default_on_key_written_with_a_quoted_default(): void
    {
        /* Arrange: the shapes the sweep has to recognise */
        $code = "env_bool('A_KEY', true); env_bool( 'B_KEY' , 'true' ); env_bool('C_KEY', \"true\"); env_bool('D_KEY', false); env_bool('E_KEY', 'false'); env_bool('F_KEY');";

        /* Act */
        preg_match_all("/env_bool\\(\\s*'([A-Z_]+)'\\s*,\\s*(?:true|'true'|\"true\")\\s*\\)/i", $code, $matches);

        /* Assert */
        self::assertSame(['A_KEY', 'B_KEY', 'C_KEY'], $matches[1]);
    }

    /**
     * bootstrap/helpers.php and bootstrap/kernel.php (twice) each define env_bool() behind function_exists(); whichever
     * loads first is the one the application runs, so the three must read values the same way.
     */
    #[Test]
    public function it_defines_env_bool_the_same_way_in_every_place_it_is_defined(): void
    {
        /* Arrange */
        $bodies = [];
        foreach (['bootstrap/helpers.php', 'bootstrap/kernel.php'] as $file) {
            preg_match_all('/function env_bool\(string \$key, bool \$default = false\): bool\n    \{\n(.*?)\n    \}\n/s', (string) file_get_contents(ROOT_PATH . '/' . $file), $found);
            foreach ($found[1] as $body) {
                $bodies[] = $body;
            }
        }

        /* Act */
        $distinct = array_values(array_unique($bodies));

        /* Assert */
        self::assertCount(3, $bodies, 'the premise: helpers.php defines it once and kernel.php twice');
        self::assertCount(1, $distinct, 'every definition of env_bool() must have the same body.');
    }

    /**
     * Boots config.php in a fresh process with only the given keys in $_ENV and returns the config array.
     *
     * @param array<string, string> $env
     *
     * @return array<string, mixed>
     */
    private function bootConfig(array $env): array
    {
        $code = 'define("BASEPATH", "x"); define("IP_DEBUG", false);'
            . 'require ' . var_export(ROOT_PATH . '/bootstrap/session_path.php', true) . ';'
            . '$config = [];'
            . 'require ' . var_export(ROOT_PATH . '/application/config/config.php', true) . ';'
            . 'echo json_encode($config);';

        return json_decode($this->probe($code, $env), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Runs PHP code in a fresh process, with the repository helpers loaded and $_ENV replaced by the given keys.
     * The process keeps the parent's environment (PHP's own configuration needs it); only $_ENV, which is what
     * env_bool() reads, is controlled.
     *
     * @param array<string, string> $env
     */
    private function probe(string $code, array $env): string
    {
        $prelude = '$_ENV = ' . var_export($env, true) . ';'
            . 'require_once ' . var_export(ROOT_PATH . '/bootstrap/helpers.php', true) . ';';

        $process = proc_open([PHP_BINARY, '-r', $prelude . $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'the probe process must start.');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), "the probe must exit cleanly:\n" . $stdout . $stderr);

        return $stdout;
    }
}
