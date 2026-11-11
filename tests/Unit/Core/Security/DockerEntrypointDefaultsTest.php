<?php

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-m999-gh8x-38v7: resources/docker/entrypoint.sh wrote SESS_REGENERATE_DESTROY=false into ipconfig.php when the
 * variable was not set, although config.php, ipconfig.php.example and the container documentation all say the
 * default is true. With false, CodeIgniter's periodic session id rotation leaves the old session usable.
 *
 * The tests run the part of the entrypoint that writes ipconfig.php (everything up to the end of the heredoc) with
 * bash and read the file it produces, so they see the value an installation really gets.
 */
#[Group('security')]
final class DockerEntrypointDefaultsTest extends TestCase
{
    private const REQUIRED = [
        'IP_URL'         => 'https://invoices.example.test',
        'DB_HOSTNAME'    => 'db',
        'DB_USERNAME'    => 'ip',
        'DB_PASSWORD'    => 'secret',
        'DB_DATABASE'    => 'ip',
        'ENCRYPTION_KEY' => 'base64:dGVzdC1rZXktdGVzdC1rZXktdGVzdC1rZXktMTIzNA==',
    ];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 4);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function explicitValues(): array
    {
        return [
            'explicit true'  => ['true', 'true'],
            'explicit false' => ['false', 'false'],
            'empty'          => ['', 'true'],
        ];
    }

    #[Test]
    public function it_destroys_the_old_session_on_id_rotation_when_the_variable_is_not_set(): void
    {
        /* Act */
        $config = $this->generateConfig([]);

        /* Assert */
        self::assertSame('true', $config['SESS_REGENERATE_DESTROY'] ?? null);
    }

    #[Test]
    #[DataProvider('explicitValues')]
    public function it_writes_the_value_the_operator_chose(string $given, string $written): void
    {
        /* Act: an operator may still switch it off; only the unset and empty cases fall back to the default */
        $config = $this->generateConfig(['SESS_REGENERATE_DESTROY' => $given]);

        /* Assert */
        self::assertSame($written, $config['SESS_REGENERATE_DESTROY'] ?? null);
    }

    #[Test]
    public function it_writes_every_default_the_container_documentation_states(): void
    {
        /*
         * The same mistake for any other variable: the documentation promises one default and the entrypoint
         * writes another. Every variable that the entrypoint writes and the documentation gives a literal default
         * for must come out with that default.
         */

        /* Arrange */
        $documented = $this->documentedDefaults();

        /* Act */
        $config = $this->generateConfig([]);

        /* Assert */
        $checked = 0;

        foreach ($documented as $name => $default) {
            if ( ! array_key_exists($name, $config) || array_key_exists($name, self::REQUIRED)) {
                continue;
            }

            self::assertSame($default, $config[$name], $name . ' is documented with one default and written with another.');
            $checked++;
        }

        self::assertGreaterThanOrEqual(15, $checked, 'The documentation table or the entrypoint could not be read.');
        self::assertArrayHasKey('SESS_REGENERATE_DESTROY', $documented);
    }

    #[Test]
    public function it_writes_the_same_session_and_header_defaults_as_the_example_configuration(): void
    {
        /* Arrange: the hardening switches a manual installation gets from ipconfig.php.example */
        $example = [];
        preg_match_all('/^([A-Z_]+)=(.*)$/m', (string) file_get_contents($this->root . '/ipconfig.php.example'), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $example[$match[1]] = trim($match[2]);
        }

        /* Act */
        $config = $this->generateConfig([]);

        /* Assert */
        foreach (['SESS_REGENERATE_DESTROY', 'SESS_MATCH_IP', 'ENABLE_X_CONTENT_TYPE_OPTIONS', 'X_FRAME_OPTIONS'] as $name) {
            self::assertArrayHasKey($name, $example, $name . ' is missing from ipconfig.php.example.');
            self::assertSame($example[$name], $config[$name] ?? null, $name . ' differs between a container and a manual installation.');
        }
    }

    #[Test]
    public function it_keeps_setup_locked_in_the_generated_configuration(): void
    {
        /* Act */
        $config = $this->generateConfig(['DISABLE_SETUP' => 'false', 'SETUP_COMPLETED' => 'false']);

        /* Assert: these two are fixed by the entrypoint and cannot be switched by the environment */
        self::assertSame('true', $config['DISABLE_SETUP'] ?? null);
        self::assertSame('true', $config['SETUP_COMPLETED'] ?? null);
    }

    /**
     * Runs the entrypoint up to the end of the heredoc that writes ipconfig.php and returns the written keys.
     *
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    private function generateConfig(array $environment): array
    {
        $script = (string) file_get_contents($this->root . '/resources/docker/entrypoint.sh');
        $end    = strpos($script, "\nEOF\n");
        self::assertNotFalse($end, 'The heredoc that writes ipconfig.php was not found.');

        $target = tempnam(sys_get_temp_dir(), 'ipconfig-');
        self::assertNotFalse($target);

        $writer = substr($script, 0, $end + 5);
        $writer = (string) preg_replace('/^CONFIG=.*$/m', 'CONFIG=' . escapeshellarg($target), $writer, 1, $replaced);
        self::assertSame(1, $replaced, 'The CONFIG path of the entrypoint was not found.');

        // An empty value is exported inside the script: handed over through the process environment it would
        // arrive as "not set", and the two cases are different ones for the shell.
        foreach ($environment as $name => $value) {
            if ($value === '') {
                $writer = 'export ' . $name . "=''\n" . $writer;
                unset($environment[$name]);
            }
        }

        $process = proc_open(['bash', '-c', $writer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(
            ['PATH'                                      => (string) getenv('PATH')],
            self::REQUIRED,
            $environment
        ));
        self::assertIsResource($process);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $written = (string) file_get_contents($target);
        unlink($target);

        self::assertSame(0, $exit, 'The entrypoint failed: ' . $errors);

        $config = [];
        preg_match_all('/^([A-Z_]+)=(.*)$/m', $written, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $config[$match[1]] = $match[2];
        }

        return $config;
    }

    /**
     * @return array<string, string> variable => literal default, from the tables in CONTAINER_DEPLOYMENT.md
     */
    private function documentedDefaults(): array
    {
        $documentation = (string) file_get_contents($this->root . '/.github/docs/CONTAINER_DEPLOYMENT.md');
        preg_match_all('/^\| `([A-Z_]+)` \| `([^`|]*)` \|/m', $documentation, $matches, PREG_SET_ORDER);

        $defaults = [];

        foreach ($matches as $match) {
            $defaults[$match[1]] = $match[2];
        }

        return $defaults;
    }
}
