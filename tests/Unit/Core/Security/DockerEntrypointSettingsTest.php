<?php

namespace Tests\Unit\Core\Security;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The container entrypoint writes ipconfig.php from environment variables. A setting it does not write cannot be
 * changed in a container at all: the application reads only ipconfig.php. COOKIE_SECURE, PROXY_IPS, the session
 * driver and the login and cron rate limits were among the settings it left out.
 *
 * The tests run the part of the entrypoint that writes ipconfig.php with bash and read the file it produces.
 */
#[Group('security')]
final class DockerEntrypointSettingsTest extends TestCase
{
    private const REQUIRED = [
        'IP_URL'         => 'https://invoices.example.test',
        'DB_HOSTNAME'    => 'db',
        'DB_USERNAME'    => 'ip',
        'DB_PASSWORD'    => 'secret',
        'DB_DATABASE'    => 'ip',
        'ENCRYPTION_KEY' => 'base64:dGVzdC1rZXktdGVzdC1rZXktdGVzdC1rZXktMTIzNA==',
    ];

    private const OPTIONAL = [
        'COOKIE_SECURE'                       => 'false',
        'PROXY_IPS'                           => '10.0.0.0/8,172.16.0.1',
        'SESS_DRIVER'                         => 'database',
        'SESS_SAVE_PATH'                      => '/var/lib/invoiceplane/sessions',
        'SESS_COOKIE_NAME'                    => 'my_session',
        'SESS_TABLE_NAME'                     => 'my_sessions',
        'CSRF_PROTECTION'                     => 'true',
        'CSRF_REGENERATE'                     => 'false',
        'CSRF_EXPIRE'                         => '3600',
        'LOGIN_IP_MAX_ATTEMPTS'               => '7',
        'LOGIN_IP_WINDOW_MINUTES'             => '30',
        'CRON_IP_MAX_ATTEMPTS'                => '4',
        'CRON_IP_WINDOW_MINUTES'              => '20',
        'PASSWORD_RESET_TOKEN_EXPIRY_MINUTES' => '45',
        'SEC_STRIP_EXIF_FROM_IMAGES'          => 'true',
        'TITLE'                               => 'My Invoices',
    ];

    /**
     * Read by the application but deliberately not taken from the container environment.
     */
    private const NOT_PASSED = [
        'DEFAULT_ADMIN_EMAIL'    => 'read from the process environment by setup/cli; a password does not belong in ipconfig.php',
        'DEFAULT_ADMIN_NAME'     => 'read from the process environment by setup/cli; a password does not belong in ipconfig.php',
        'DEFAULT_ADMIN_PASSWORD' => 'read from the process environment by setup/cli; a password does not belong in ipconfig.php',
        'EXTENSIVE_LOGGING'      => 'development aid',
        'PAYPAL_MOCK_RESPONSES'  => 'test seam',
        'STRIPE_MOCK_RESPONSES'  => 'test seam',
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function optionalSettings(): array
    {
        $cases = [];

        foreach (self::OPTIONAL as $name => $value) {
            $cases[$name] = [$name, $value];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('optionalSettings')]
    public function it_writes_a_setting_the_operator_set(string $name, string $value): void
    {
        /* Act */
        $config = $this->generateConfig([$name => $value]);

        /* Assert */
        self::assertSame($value, $config[$name] ?? null);
    }

    #[Test]
    #[DataProvider('optionalSettings')]
    public function it_leaves_a_setting_out_when_it_is_not_set_or_empty(string $name): void
    {
        /* Act: an empty line would override the application default with nothing (an empty SESS_SAVE_PATH breaks
         * sessions), so only a real value may be written */
        $unset = $this->generateConfig([]);
        $empty = $this->generateConfig([$name => '']);

        /* Assert */
        self::assertArrayNotHasKey($name, $unset);
        self::assertArrayNotHasKey($name, $empty);
        self::assertSame('true', $unset['SETUP_COMPLETED'] ?? null);
    }

    #[Test]
    #[DataProvider('optionalSettings')]
    public function it_refuses_a_value_that_would_add_a_line_to_the_configuration(string $name): void
    {
        /* Act */
        [$exit, $config] = $this->runEntrypoint([$name => "x\nDISABLE_SETUP=false"]);

        /* Assert */
        self::assertNotSame(0, $exit);
        self::assertStringNotContainsString('DISABLE_SETUP=false', $config);
    }

    #[Test]
    public function it_writes_all_settings_together_without_losing_the_fixed_ones(): void
    {
        /* Act */
        $config = $this->generateConfig(self::OPTIONAL);

        /* Assert */
        foreach (self::OPTIONAL as $name => $value) {
            self::assertSame($value, $config[$name] ?? null, $name);
        }

        self::assertSame('true', $config['DISABLE_SETUP'] ?? null);
        self::assertSame('true', $config['SETUP_COMPLETED'] ?? null);
        self::assertSame(self::REQUIRED['IP_URL'], $config['IP_URL'] ?? null);
    }

    #[Test]
    public function it_can_set_every_setting_the_application_reads(): void
    {
        /* Arrange: every name the application asks env() or env_bool() for */
        $read = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/application', FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match_all("/\\benv(?:_bool)?\\(\\s*'([A-Z][A-Z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $matches)) {
                $read = array_merge($read, $matches[1]);
            }
        }

        $read = array_values(array_unique($read));
        sort($read);
        self::assertGreaterThan(30, count($read));

        /* Act: set every one of them and see what comes out */
        $config = $this->generateConfig(array_fill_keys($read, 'value'));

        /* Assert */
        $missing = array_values(array_diff($read, array_keys($config), array_keys(self::NOT_PASSED)));
        self::assertSame([], $missing, 'The application reads these settings, but a container cannot set them.');
    }

    /**
     * @param array<string, string> $env
     *
     * @return array<string, string>
     */
    private function generateConfig(array $env): array
    {
        [$exit, $raw] = $this->runEntrypoint($env);
        self::assertSame(0, $exit, $raw);

        $config = [];

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $match)) {
                $config[$match[1]] = $match[2];
            }
        }

        return $config;
    }

    /**
     * Runs the entrypoint up to the point where it starts touching the container (template folder, migration).
     *
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    private function runEntrypoint(array $env): array
    {
        $script = (string) file_get_contents($this->root . '/resources/docker/entrypoint.sh');
        $end    = mb_strpos($script, '# Protect the custom templates directory');
        self::assertNotFalse($end);

        $target = tempnam(sys_get_temp_dir(), 'ipconfig');
        $script = str_replace('CONFIG="/var/www/html/ipconfig.php"', 'CONFIG=' . escapeshellarg($target), mb_substr($script, 0, $end));
        $file   = tempnam(sys_get_temp_dir(), 'entrypoint');
        file_put_contents($file, $script);

        $process = proc_open(['bash', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(self::REQUIRED, $env));
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        $config = (string) file_get_contents($target);
        unlink($target);
        unlink($file);

        return [$exit, $config];
    }
}
