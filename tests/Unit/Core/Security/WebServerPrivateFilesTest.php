<?php

namespace Tests\Unit\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ApacheRewriteRules;
use Tests\Support\NginxLocationResolver;

/**
 * GHSA-83qx-qhxp-crhc: web.config (IIS) refuses the private folders, dot files, project files, ipconfig.php
 * backups and every PHP file but index.php. The htaccess template (Apache) and the bundled nginx configuration did
 * not, so an installation served from the project folder handed .git/config, composer.json or ipconfig.php.bak to
 * anyone.
 *
 * The three files are checked against one list of paths, so they cannot drift apart again. Requests are not sent
 * to a real server here: the rules are evaluated the way each server documents it (tests/Support).
 */
#[Group('security')]
final class WebServerPrivateFilesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /** Files in the project folder that a browser may fetch. Everything else there is private. */
    private const PUBLIC_ROOT_FILES = ['index.php', 'favicon.ico', 'robots.txt'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function privatePaths(): array
    {
        $paths = [
            // private folders
            '/application/config/config.php', '/application/logs/log-2026-11-11.php', '/application', '/application/',
            '/vendor/autoload.php', '/vendor/composer/installed.json', '/storage/sessions/ci_session0123',
            '/resources/docker/entrypoint.sh', '/node_modules/jquery/package.json', '/tests/bootstrap.php',
            '/uploads/archive/Invoice_1.pdf', '/uploads/customer_files/contract.pdf', '/uploads/import/clients.csv',
            '/uploads/temp/sumex.xml', '/uploads//archive/Invoice_1.pdf',
            // the same on a file system that ignores case
            '/Application/config/config.php', '/UPLOADS/Archive/Invoice_1.pdf', '/VENDOR/autoload.php',
            // dot files and dot folders
            '/.git/config', '/.git/HEAD', '/.env', '/.github/workflows/release.yml', '/.gitignore', '/.htaccess',
            '/uploads/.hidden', '/.well-knownx', '/.well-known.bak/x',
            // project files
            '/composer.json', '/composer.lock', '/COMPOSER.JSON', '/package.json', '/package-lock.json', '/yarn.lock',
            '/README.md', '/CLAUDE.md', '/SECURITY.md', '/phpcs.xml', '/phpunit.xml', '/phpstan.neon',
            '/phpstan-baseline.neon', '/pint.json', '/rector.php', '/Gruntfile.js', '/compose.yml',
            '/docker-compose.yml', '/crowdin.yml', '/Dockerfile', '/Makefile', '/playwright.config.js',
            '/LICENSE.txt', '/htaccess', '/web.config',
            // the configuration and every copy of it
            '/ipconfig.php', '/ipconfig.php.example', '/ipconfig.php.bak', '/ipconfig.php.old', '/ipconfig.php~',
            '/ipconfig.php.2026-11-11', '/IPCONFIG.PHP.BAK',
            // PHP files other than the front controller
            '/uploads/shell.php', '/uploads/shell.PHP', '/assets/core/evil.php', '/uploads/shell.php/extra',
            '/info.php', '/index.php.php', '/sub/index.php',
        ];

        return array_combine($paths, array_map(static fn (string $path): array => [$path], $paths));
    }

    /**
     * @return array<string, array{0: string, 1: bool}> path, and whether it is an existing file or folder
     */
    public static function publicPaths(): array
    {
        return [
            'home'                         => ['/', true],
            'front controller'             => ['/index.php', true],
            'front controller with a path' => ['/index.php/invoices/view/1', true],
            'clean url'                    => ['/invoices/view/1', false],
            'login'                        => ['/sessions/login', false],
            'import module'                => ['/import', false],
            'import module form'           => ['/import/form', false],
            'upload module'                => ['/upload/get_file/abc', false],
            'guest invoice link'           => ['/guest/view/invoice/0123456789abcdef0123456789abcdef', false],
            'settings'                     => ['/settings', false],
            'stylesheet'                   => ['/assets/core/css/style.css', true],
            'script'                       => ['/assets/core/js/scripts.js', true],
            'favicon'                      => ['/favicon.ico', true],
            'robots'                       => ['/robots.txt', true],
            'uploaded logo'                => ['/uploads/logo.png', true],
            'acme challenge'               => ['/.well-known/acme-challenge/token', true],
            'security.txt'                 => ['/.well-known/security.txt', true],
            'a route that mentions php'    => ['/reports/phpinfo', false],
            'a markdown name in a route'   => ['/clients/view/notes.md', false],
        ];
    }

    #[Test]
    #[DataProvider('privatePaths')]
    public function it_refuses_a_private_path_on_apache(string $path): void
    {
        /* Act: as an existing file, the worst case, because Apache serves files that exist */
        $outcome = $this->apache()->outcome($path, true);

        /* Assert */
        self::assertSame(ApacheRewriteRules::REFUSED, $outcome, 'htaccess serves ' . $path);
    }

    #[Test]
    #[DataProvider('privatePaths')]
    public function it_refuses_a_private_path_on_nginx(string $path): void
    {
        /* Act */
        $location = $this->nginx()->resolve($path);

        /* Assert */
        self::assertTrue(
            NginxLocationResolver::refuses($location),
            $path . ' is handled by `location ' . trim(($location['modifier'] ?? '') . ' ' . ($location['pattern'] ?? '(none)')) . '`, which does not refuse it.'
        );
    }

    #[Test]
    #[DataProvider('privatePaths')]
    public function it_refuses_a_private_path_on_iis(string $path): void
    {
        /* Assert: the file the other two are kept in step with */
        self::assertTrue($this->iisRefuses($path), 'web.config serves ' . $path);
    }

    #[Test]
    #[DataProvider('publicPaths')]
    public function it_keeps_a_public_path_reachable_on_all_three_servers(string $path, bool $exists): void
    {
        /* Act */
        $apache = $this->apache()->outcome($path, $exists);
        $nginx  = $this->nginx()->resolve($path);

        /* Assert: a rule set that refuses everything would pass every test above */
        self::assertSame($exists ? ApacheRewriteRules::AS_IS : ApacheRewriteRules::FRONT_CONTROLLER, $apache, 'htaccess: ' . $path);
        self::assertFalse(NginxLocationResolver::refuses($nginx), 'nginx refuses ' . $path);
        self::assertFalse($this->iisRefuses($path), 'web.config refuses ' . $path);
    }

    #[Test]
    public function it_refuses_every_file_in_the_project_folder_that_is_not_meant_for_a_browser(): void
    {
        /*
         * Whatever is added to the project folder later (a new tool's configuration, another lock file) is private
         * until it is listed in PUBLIC_ROOT_FILES. This test fails for a new file that the rules do not know.
         */

        /* Arrange */
        $files = array_values(array_filter(
            scandir(self::ROOT) ?: [],
            static fn (string $name): bool => is_file(self::ROOT . '/' . $name) && ! in_array($name, self::PUBLIC_ROOT_FILES, true)
        ));

        /* Assert */
        self::assertGreaterThan(20, count($files), 'The project folder was not read.');

        foreach ($files as $name) {
            self::assertSame(ApacheRewriteRules::REFUSED, $this->apache()->outcome('/' . $name, true), 'htaccess serves /' . $name);
            self::assertTrue(NginxLocationResolver::refuses($this->nginx()->resolve('/' . $name)), 'nginx serves /' . $name);
        }
    }

    #[Test]
    public function it_refuses_php_files_on_nginx_before_they_reach_the_php_handler(): void
    {
        /* Arrange: nginx tries regex locations in file order, so the refusals must come before "\.php$" */
        $patterns = array_column($this->nginx()->locations(), 'pattern');

        /* Act */
        $handler = array_search('\.php$', $patterns, true);
        $refusal = array_search('^/+(?!index\.php(/|$)).*\.php(/|$)', $patterns, true);

        /* Assert */
        self::assertNotFalse($handler, 'The PHP handler location was not found.');
        self::assertNotFalse($refusal, 'The location that refuses other PHP files was not found.');
        self::assertLessThan($handler, $refusal);
        self::assertSame('\.php$', $this->nginx()->resolve('/index.php')['pattern'], 'The front controller must still reach the PHP handler.');
    }

    #[Test]
    public function it_reads_every_location_of_the_nginx_configuration(): void
    {
        /*
         * A location pattern with a space or a brace in it must be quoted, or nginx does not start. The resolver
         * would skip such a line and the tests above would then judge a configuration no server can load.
         */

        /* Arrange */
        $config = (string) preg_replace('/(^|\s)#.*$/m', '$1', (string) file_get_contents(self::ROOT . '/resources/docker/nginx/invoiceplane.conf'));

        /* Act */
        $written = preg_match_all('/^\s*location\b/m', $config);

        /* Assert */
        self::assertSame($written, count($this->nginx()->locations()));
    }

    #[Test]
    public function it_refuses_each_group_through_its_own_apache_rule(): void
    {
        /* Arrange: with one rule taken out, exactly its group is served again, so no rule is covered by another */
        $groups = [
            'application|vendor' => '/storage/sessions/ci_session0123',
            'well-known'         => '/.git/config',
            'composer'           => '/composer.json',
        ];

        foreach ($groups as $rule => $path) {
            /* Act */
            $outcome = $this->apache()->without($rule)->outcome($path, true);

            /* Assert */
            self::assertSame(ApacheRewriteRules::AS_IS, $outcome, 'Another rule also refuses ' . $path);
        }

        self::assertSame(ApacheRewriteRules::AS_IS, $this->apache()->without('\.php[')->outcome('/uploads/shell.php', true));
    }

    private function apache(): ApacheRewriteRules
    {
        return ApacheRewriteRules::fromHtaccess((string) file_get_contents(self::ROOT . '/htaccess'));
    }

    private function nginx(): NginxLocationResolver
    {
        return NginxLocationResolver::fromConfig((string) file_get_contents(self::ROOT . '/resources/docker/nginx/invoiceplane.conf'));
    }

    private function iisRefuses(string $path): bool
    {
        $xml = simplexml_load_string((string) file_get_contents(self::ROOT . '/web.config'));
        self::assertNotFalse($xml);

        // IIS matches the URL without its leading slash and stops at the first rule that matches.
        $url = ltrim($path, '/');

        // IIS never serves web.config itself (it is a hidden segment of request filtering), so the file has no rule for it.
        if (strcasecmp($url, 'web.config') === 0) {
            return true;
        }

        foreach ($xml->xpath('//rule') ?: [] as $rule) {
            if ((string) $rule['enabled'] === 'false') {
                continue;
            }

            $regex = '#' . str_replace('#', '\#', (string) $rule->match['url']) . '#' . ((string) $rule->match['ignoreCase'] === 'true' ? 'i' : '');

            if (preg_match($regex, $url) === 1) {
                return (string) $rule->action['statusCode'] === '404';
            }
        }

        return false;
    }
}
