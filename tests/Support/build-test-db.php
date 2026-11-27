<?php

/**
 * Build the schema of the test database the way an installation gets it: through
 * Mdl_Setup::install_tables() and Mdl_Setup::upgrade_tables(), so the SQL files and the PHP
 * upgrade_*() steps both run and a change to either is exercised by every test.
 *
 * The database must exist and be empty; it is left with the full schema and no rows. Connection details come from the DB_* environment
 * variables (as in seed-test-db.php), falling back to the conventional CI values.
 *
 * Usage:
 *   DB_HOSTNAME=127.0.0.1 DB_DATABASE=invoiceplane_test \
 *   DB_USERNAME=root DB_PASSWORD=root php tests/Support/build-test-db.php
 */
$host = getenv('DB_HOSTNAME') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_DATABASE') ?: 'invoiceplane_test';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: 'root';

require_once dirname(__DIR__) . '/bootstrap.php';

require_once BASEPATH . 'core/Common.php';
require_once BASEPATH . 'database/DB.php';
require_once BASEPATH . 'core/Model.php';
require_once BASEPATH . 'helpers/directory_helper.php';
require_once APPPATH . 'helpers/sql_helper.php';
require_once APPPATH . 'helpers/ip_security_helper.php';
foreach (['MY_Model', 'Form_Validation_Model', 'Response_Model'] as $base) {
    require_once APPPATH . 'core/' . $base . '.php';
}
require_once APPPATH . 'modules/setup/models/Mdl_setup.php';

$ci     = new stdClass();
$ci->db = DB([
    'dsn'      => '', 'hostname' => $host, 'port' => (int) $port, 'username' => $user, 'password' => $pass,
    'database' => $name, 'dbdriver' => 'mysqli', 'dbprefix' => '', 'pconnect' => false, 'db_debug' => true,
    'cache_on' => false, 'cachedir' => '', 'char_set' => 'utf8mb4', 'dbcollat' => 'utf8mb4_general_ci', 'swap_pre' => '',
    'encrypt'  => false, 'compress' => false, 'stricton' => false, 'failover' => [], 'save_queries' => false,
]);
$ci->session = new class () {
    /** @var array<string, mixed> */
    public array $data = ['ip_lang' => 'english'];

    public function userdata(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set_userdata(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
};
$ci->load = new class () {
    public function helper(string|array $helpers): void
    {
        foreach ((array) $helpers as $helper) {
            $file = APPPATH . 'helpers/' . $helper . '_helper.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    public function library(string $name): void
    {
        $GLOBALS['unitCiInstance']->{$name} ??= new stdClass();
    }

    public function get_package_paths(bool $include_base = false): array
    {
        return $include_base ? [APPPATH, BASEPATH] : [APPPATH];
    }

    public function model(string|array $models, ?string $alias = null): void
    {
        foreach ((array) $models as $path) {
            $name  = basename($path);
            $files = glob(APPPATH . 'modules/*/models/' . ucfirst($name) . '.php') ?: [];
            if ($files === []) {
                throw new RuntimeException('Model not found: ' . $path);
            }
            require_once $files[0];
            $GLOBALS['unitCiInstance']->{$alias ?? strtolower($name)} = new $name();
        }
    }
};
$GLOBALS['unitCiInstance'] = $ci;

$setup = new Mdl_Setup();
$setup->install_tables();
$setup->upgrade_tables();

/* An error the installer is known to report on this server can be named in TEST_DB_KNOWN_INSTALLER_ERRORS
   (substrings, separated by "|"). It is printed, and the build goes on: the installer itself carries on
   past a failed statement too. Any other error stops the build. */
$known      = array_filter(explode('|', (string) getenv('TEST_DB_KNOWN_INSTALLER_ERRORS')));
$unexpected = [];
foreach ($setup->errors as $error) {
    foreach ($known as $needle) {
        if (str_contains($error, $needle)) {
            fwrite(STDOUT, "Known installer error on this server (tolerated): {$error}" . PHP_EOL);

            continue 2;
        }
    }
    $unexpected[] = $error;
}

if ($unexpected !== []) {
    fwrite(STDERR, "Building the schema of {$name} failed:" . PHP_EOL . implode(PHP_EOL, $unexpected) . PHP_EOL);
    exit(1);
}

$files = (int) $ci->db->count_all('ip_versions');

/* The installer also writes rows (default settings, invoice groups, payment methods, the applied versions).
   The suite seeds its own baseline (seed-test-db.php), so the tables start empty, as they always have. */
foreach ($ci->db->list_tables() as $table) {
    if ($ci->db->count_all($table) > 0) {
        $ci->db->truncate($table);
    }
}

fwrite(STDOUT, "Schema of {$name} built through Mdl_Setup ({$files} migration files applied)." . PHP_EOL);
