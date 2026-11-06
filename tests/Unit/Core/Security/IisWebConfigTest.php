<?php

namespace Tests\Unit\Core\Security;

use DOMDocument;
use DOMElement;
use DOMXPath;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * IIS ignores .htaccess, and the release shipped no web.config, so on a default IIS install archived invoice
 * PDFs (uploads/archive/<date>_Invoice_<n>.pdf, predictable names), customer files, import files, vendor/ and
 * the project files were downloadable without logging in (the IIS twin of GHSA-qq8q-gf24-576m).
 *
 * IIS cannot run in this suite, so the tests evaluate web.config the way URL Rewrite does: every rule is a
 * regular expression matched against the request path without its leading slash and query string, case
 * insensitive unless told otherwise, first match with stopProcessing wins. Three oracles keep it honest: a list
 * of paths that must be refused, a list of paths that must keep working, and a parity check against the deny
 * rules the Apache (htaccess, the .htaccess files in private folders) and nginx configurations already carry.
 */
#[Group('security')]
final class IisWebConfigTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function privatePaths(): array
    {
        return [
            'archived invoice pdf with the old predictable name' => ['uploads/archive/2017-09-28_Invoice_1.pdf'],
            'archived invoice pdf with a random name'            => ['uploads/archive/3f9c2b7a1d4e4c58a1b0d6e2f7a9c001_Invoice_12.pdf'],
            'archive folder listing'                             => ['uploads/archive/'],
            'archive folder without a slash'                     => ['uploads/archive'],
            'customer file'                                      => ['uploads/customer_files/clientA_contract.pdf'],
            'import file'                                        => ['uploads/import/clients.csv'],
            'import folder listing'                              => ['uploads/import/'],
            'temp file'                                          => ['uploads/temp/invoice.xml'],
            'application config'                                 => ['application/config/database.php'],
            'application cache'                                  => ['application/cache/sessions/ci_session'],
            'application folder'                                 => ['application'],
            'vendor script'                                      => ['vendor/mpdf/mpdf/src/Mpdf.php'],
            'vendor autoload'                                    => ['vendor/autoload.php'],
            'vendor data that is not php'                        => ['vendor/composer/installed.json'],
            'vendor documentation'                               => ['vendor/mpdf/mpdf/README.md'],
            'storage'                                            => ['storage/logs/app.log'],
            'resources'                                          => ['resources/docker/nginx/invoiceplane.conf'],
            'node_modules'                                       => ['node_modules/jquery/package.json'],
            'tests'                                              => ['tests/bootstrap.php'],
            'test fixtures that are not php'                     => ['tests/Fixtures/integrations/data.json'],
            'composer.json'                                      => ['composer.json'],
            'composer.lock'                                      => ['composer.lock'],
            'package.json'                                       => ['package.json'],
            'package-lock.json'                                  => ['package-lock.json'],
            'yarn.lock'                                          => ['yarn.lock'],
            'readme'                                             => ['README.md'],
            'markdown inside a dot folder'                       => ['.github/CHANGELOG.md'],
            'markdown at the root'                               => ['SECURITY.md'],
            'ipconfig.php'                                       => ['ipconfig.php'],
            'ipconfig.php.example'                               => ['ipconfig.php.example'],
            'env file'                                           => ['.env'],
            'git directory'                                      => ['.git/config'],
            'dotfile in a subfolder'                             => ['assets/.htpasswd'],
            'htaccess template'                                  => ['htaccess'],
            'rector'                                             => ['rector.php'],
            'phpstan config'                                     => ['phpstan.neon'],
            'phpstan baseline'                                   => ['phpstan-baseline.neon'],
            'phpunit config'                                     => ['phpunit.xml'],
            'pint'                                               => ['pint.json'],
            'phpcs'                                              => ['phpcs.xml'],
            'gruntfile'                                          => ['Gruntfile.js'],
            'compose file'                                       => ['compose.yml'],
            'docker compose file'                                => ['docker-compose.yml'],
            'crowdin'                                            => ['crowdin.yml'],
            'dockerfile'                                         => ['Dockerfile'],
            'license'                                            => ['LICENSE.txt'],
            'a php file that is not the front controller'        => ['uploads/shell.php'],
            'another php file in public'                         => ['public/shell.php'],
            'a php file in public behind a double slash'         => ['public//shell.php'],
            'a php file in assets'                               => ['assets/default/js/x.php'],
            'a php file with trailing path info'                 => ['uploads/shell.php/index.php'],
            'a php file followed by extra path info'             => ['uploads/shell.php/extra'],
            'a php file in assets followed by path info'         => ['assets/x.php/foo'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reachablePaths(): array
    {
        return [
            'front controller'                              => ['index.php'],
            'front controller in public'                    => ['public/index.php'],
            'route below the public front controller'       => ['public/index.php/sessions/login'],
            'public front controller behind a double slash' => ['public//index.php/sessions/login'],
            'stylesheet in public'                          => ['public/assets/default/css/style.css'],
            'route below the front controller'              => ['index.php/sessions/login'],
            'import route is not the import folder'         => ['index.php/import'],
            'archive route is not the archive folder'       => ['index.php/uploads/archive/download'],
            'application route'                             => ['index.php/application'],
            'a route whose last segment ends in php'        => ['index.php/guest/gateways/stripe/callback/cs_test_123'],
            'site root'                                     => [''],
            'stylesheet'                                    => ['assets/default/css/style.css'],
            'logo image'                                    => ['assets/default/img/logo.png'],
            'favicon'                                       => ['favicon.ico'],
            'robots'                                        => ['robots.txt'],
            'company logo in uploads'                       => ['uploads/logo_300x150.jpg'],
            'uploads index'                                 => ['uploads/index.html'],
            'folder that merely starts like archive'        => ['uploads/archive_old.png'],
            'folder that merely starts like import'         => ['uploads/imports/readme.txt'],
            'folder that merely starts like vendor'         => ['vendors/logo.png'],
            'folder that merely starts like application'    => ['applications/form.png'],
            'asset folder called application'               => ['assets/application/x.png'],
            'asset folder called vendor'                    => ['assets/core/vendor/x.js'],
            'storage lookalike'                             => ['storagefront/x.png'],
            'lets encrypt challenge'                        => ['.well-known/acme-challenge/Xc9k2'],
            'security.txt'                                  => ['.well-known/security.txt'],
            'root image whose name contains .md'            => ['help.md.png'],
            'root file that only starts like composer.json' => ['composer.json.png'],
            'file named like a markdown folder'             => ['assets/readme/guide.png'],
            'json file that is not composer'                => ['assets/locales/en.json'],
            'php lookalike extension'                       => ['assets/phpinfo.png'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function windowsAliases(): array
    {
        return [
            'double slash inside the path'  => ['uploads//archive/2017-09-28_Invoice_1.pdf'],
            'triple slash inside the path'  => ['uploads///customer_files/a.pdf'],
            'trailing dot on the folder'    => ['uploads/archive./2017-09-28_Invoice_1.pdf'],
            'trailing space on the folder'  => ['uploads/import /clients.csv'],
            'trailing dot on composer.json' => ['composer.json.'],
            'trailing dot on a vendor path' => ['vendor./autoload.php'],
            'trailing dot on a markdown'    => ['README.md.'],
        ];
    }

    #[Test]
    public function it_ships_a_well_formed_web_config_in_the_project_root(): void
    {
        /* Arrange */
        $file = ROOT_PATH . '/web.config';

        /* Act */
        $dom = $this->loadWebConfig();

        /* Assert */
        self::assertFileExists($file, 'The release package needs a web.config next to index.php and htaccess.');
        self::assertSame('configuration', $dom->documentElement?->nodeName);
        self::assertNotNull((new DOMXPath($dom))->query('/configuration/system.webServer/rewrite/rules')->item(0), 'The rules live in system.webServer/rewrite/rules (IIS URL Rewrite).');
        self::assertGreaterThanOrEqual(2, count($this->rules()), 'One rule for private folders, one for project files.');
    }

    #[Test]
    public function it_refuses_with_a_404_custom_response_and_stops_at_the_first_match(): void
    {
        /* Arrange */
        $rules = $this->rules();

        /* Act + Assert */
        foreach ($rules as $rule) {
            self::assertSame('true', $rule['stopProcessing'], "Rule \"{$rule['name']}\" must stop processing so no later rule can rewrite the refusal.");
            self::assertSame('CustomResponse', $rule['actionType'], "Rule \"{$rule['name']}\" must answer itself, not rewrite or redirect.");
            self::assertSame('404', $rule['statusCode'], "Rule \"{$rule['name']}\" must not reveal that the file exists.");
            self::assertSame('Not Found', $rule['statusReason']);
        }
    }

    #[Test]
    public function it_only_uses_regex_syntax_that_php_and_iis_url_rewrite_read_the_same_way(): void
    {
        /* Arrange */
        $rules     = $this->rules();
        $notShared = ['(?<', '(?>', '(?i)', '(?s)', '(?x)', '\\A', '\\z', '\\Z', '\\h', '\\K', '++', '*+', '?+', '(?P', '(?#', '\\p{', '\\R'];

        /* Act + Assert: the simulation below is only valid inside the shared subset */
        foreach ($rules as $rule) {
            self::assertSame('ECMAScript', $rule['patternSyntax'], "Rule \"{$rule['name']}\" must use the default ECMAScript syntax, not Wildcard or ExactMatch.");
            self::assertSame('true', $rule['ignoreCase'], "Rule \"{$rule['name']}\" must ignore case: the Windows file system does.");
            self::assertSame('false', $rule['negate'], "Rule \"{$rule['name']}\" must not be negated.");
            foreach ($notShared as $construct) {
                self::assertStringNotContainsString($construct, $rule['pattern'], "Rule \"{$rule['name']}\" uses {$construct}, which PCRE and ECMAScript do not share.");
            }
            self::assertNotFalse(@preg_match($this->delimit($rule['pattern'], true), ''), "Rule \"{$rule['name']}\" is not a valid regular expression.");
        }
    }

    #[Test]
    #[DataProvider('privatePaths')]
    public function it_refuses_a_private_path(string $path): void
    {
        /* Arrange: see the data provider */

        /* Act */
        $status = $this->statusFor($path);

        /* Assert */
        self::assertSame(404, $status, "/{$path} must not be served.");
    }

    #[Test]
    #[DataProvider('reachablePaths')]
    public function it_leaves_a_reachable_path_alone(string $path): void
    {
        /* Arrange: see the data provider */

        /* Act */
        $status = $this->statusFor($path);

        /* Assert */
        self::assertNull($status, "/{$path} must still reach IIS, the PHP handler or the static file handler.");
    }

    #[Test]
    #[DataProvider('privatePaths')]
    public function it_refuses_a_private_path_whatever_the_case(string $path): void
    {
        /* Arrange: Windows paths are case insensitive, so UPLOADS/ARCHIVE/x.pdf is the same file */
        $shouted = strtoupper($path);

        /* Act */
        $status = $this->statusFor($shouted);

        /* Assert */
        self::assertSame(404, $status, "/{$shouted} must be refused as well.");
    }

    #[Test]
    #[DataProvider('windowsAliases')]
    public function it_refuses_the_windows_spellings_of_a_private_path(string $alias): void
    {
        /* Arrange: Windows collapses repeated slashes and ignores trailing dots and spaces in names */

        /* Act */
        $status = $this->statusFor($alias);

        /* Assert */
        self::assertSame(404, $status, "/{$alias} reaches the same file as its plain spelling and must be refused.");
    }

    #[Test]
    public function it_refuses_whatever_apache_refuses(): void
    {
        /* Arrange: the Apache deny rules of the project's own htaccess are the reference */
        $apache = $this->apacheDenyPatterns();
        $paths  = array_merge(array_column(self::privatePaths(), 0), array_column(self::reachablePaths(), 0));

        /* Act */
        $refusedByApacheButServedByIis = [];
        foreach ($paths as $path) {
            foreach ($apache as $pattern) {
                if (preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $path) === 1 && $this->statusFor($path) !== 404) {
                    $refusedByApacheButServedByIis[$path] = $pattern;
                }
            }
        }

        /* Assert */
        self::assertNotSame([], $apache, 'htaccess carries no 404 RewriteRule to compare with; the parity check would be empty.');
        self::assertSame([], $refusedByApacheButServedByIis, 'Apache refuses these paths, IIS would serve them.');
    }

    #[Test]
    public function it_refuses_whatever_nginx_refuses(): void
    {
        /* Arrange: the hardened nginx configuration (#1716) is the other reference. Its exact-match location for
         * the front controller wins over the generic "*.php returns 404" pattern, so the front controller is exempt,
         * wherever the site root puts it (index.php, or public/index.php when the site root is the project root). */
        $nginx = $this->nginxDenyPatterns();
        $paths = array_merge(array_column(self::privatePaths(), 0), array_column(self::reachablePaths(), 0));

        /* Act */
        $refusedByNginxButServedByIis = [];
        foreach ($paths as $path) {
            if (preg_match('~^(public/+)?index\.php(/|$)~', $path) === 1 || $path === '') {
                continue;
            }
            foreach ($nginx as $pattern) {
                if (preg_match('~' . str_replace('~', '\\~', $pattern) . '~', '/' . $path) === 1 && $this->statusFor($path) !== 404) {
                    $refusedByNginxButServedByIis[$path] = $pattern;
                }
            }
        }

        /* Assert */
        self::assertNotSame([], $nginx, 'The nginx configuration carries no 404 location to compare with.');
        self::assertSame([], $refusedByNginxButServedByIis, 'nginx refuses these paths, IIS would serve them.');
    }

    #[Test]
    public function it_refuses_every_folder_that_a_deny_htaccess_protects(): void
    {
        /* Arrange: a folder that ships "Deny from all" is private on Apache; a new one must not be forgotten on IIS */
        $folders = $this->foldersDeniedByHtaccess();

        /* Act */
        $served = [];
        foreach ($folders as $folder) {
            foreach ([$folder . '/', $folder . '/anything.pdf', $folder . '/deeper/anything.pdf'] as $path) {
                if ($this->statusFor($path) !== 404) {
                    $served[] = $path;
                }
            }
        }

        /* Assert */
        self::assertContains('uploads/archive', $folders, 'The discovery must find the archive folder, otherwise it checks nothing.');
        self::assertContains('application', $folders);
        self::assertSame([], $served, 'These folders are denied by an .htaccess but served by IIS.');
    }

    private function loadWebConfig(): DOMDocument
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = is_file(ROOT_PATH . '/web.config') && $dom->load(ROOT_PATH . '/web.config');
        $errors = libxml_get_errors();
        libxml_clear_errors();

        self::assertTrue($loaded, 'web.config must exist and be well-formed XML. ' . ($errors[0]->message ?? ''));

        return $dom;
    }

    /**
     * @return list<array{name: string, pattern: string, ignoreCase: string, negate: string, patternSyntax: string, stopProcessing: string, actionType: string, statusCode: string, statusReason: string}>
     */
    private function rules(): array
    {
        $xpath = new DOMXPath($this->loadWebConfig());
        $rules = [];

        foreach ($xpath->query('/configuration/system.webServer/rewrite/rules/rule') as $rule) {
            /** @var DOMElement $rule */
            $match  = $xpath->query('match', $rule)->item(0);
            $action = $xpath->query('action', $rule)->item(0);
            self::assertInstanceOf(DOMElement::class, $match, 'Every rule needs a match element.');
            self::assertInstanceOf(DOMElement::class, $action, 'Every rule needs an action element.');
            self::assertSame(0, $xpath->query('conditions', $rule)->length, 'Conditions are not simulated; the refusal rules must not depend on any.');

            $rules[] = [
                'name'           => $rule->getAttribute('name'),
                'pattern'        => $match->getAttribute('url'),
                'ignoreCase'     => $match->hasAttribute('ignoreCase') ? $match->getAttribute('ignoreCase') : 'true',
                'negate'         => $match->hasAttribute('negate') ? $match->getAttribute('negate') : 'false',
                'patternSyntax'  => $rule->hasAttribute('patternSyntax') ? $rule->getAttribute('patternSyntax') : 'ECMAScript',
                'stopProcessing' => $rule->hasAttribute('stopProcessing') ? $rule->getAttribute('stopProcessing') : 'false',
                'actionType'     => $action->getAttribute('type'),
                'statusCode'     => $action->getAttribute('statusCode'),
                'statusReason'   => $action->getAttribute('statusReason'),
            ];
        }

        return $rules;
    }

    /**
     * The status IIS URL Rewrite would answer with, or null when no rule refuses the request.
     */
    private function statusFor(string $path): ?int
    {
        foreach ($this->rules() as $rule) {
            if (preg_match($this->delimit($rule['pattern'], $rule['ignoreCase'] === 'true'), $path) === 1) {
                return $rule['actionType'] === 'CustomResponse' ? (int) $rule['statusCode'] : null;
            }
        }

        return null;
    }

    private function delimit(string $pattern, bool $ignoreCase): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~' . ($ignoreCase ? 'i' : '');
    }

    /**
     * @return list<string> the patterns of "RewriteRule <pattern> - [R=404,L]"
     */
    private function apacheDenyPatterns(): array
    {
        $htaccess = (string) @file_get_contents(ROOT_PATH . '/htaccess');
        preg_match_all('/^\s*RewriteRule\s+(\S+)\s+-\s+\[R=404,L\]/m', $htaccess, $matches);

        return $matches[1];
    }

    /**
     * @return list<string> the regular expressions of "location ~ <pattern> { return 404; }"
     */
    private function nginxDenyPatterns(): array
    {
        $conf = (string) @file_get_contents(ROOT_PATH . '/resources/docker/nginx/invoiceplane.conf');
        preg_match_all('/location\s+~\s+(\S+)\s*\{\s*return\s+404;/', $conf, $matches);

        return $matches[1];
    }

    /**
     * @return list<string> project relative folders that carry an .htaccess with "Deny from all"
     */
    private function foldersDeniedByHtaccess(): array
    {
        $skip     = ['vendor', 'node_modules', '.git', '.sandbox-tools', '.phpunit.cache'];
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator(ROOT_PATH, FilesystemIterator::SKIP_DOTS),
            static fn ($current): bool => ! ($current->isDir() && in_array($current->getFilename(), $skip, true))
        ));

        $folders = [];
        foreach ($iterator as $file) {
            if ($file->getFilename() === '.htaccess' && preg_match('/^\s*(Deny from all|Require all denied)\s*$/mi', (string) file_get_contents($file->getPathname())) === 1) {
                $folders[] = ltrim(str_replace('\\', '/', substr(dirname($file->getPathname()), strlen(ROOT_PATH))), '/');
            }
        }
        sort($folders);

        return $folders;
    }
}
