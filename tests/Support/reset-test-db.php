<?php

/**
 * Truncate every table in the test database and reseed the baseline rows.
 *
 * This is the E2E-suite equivalent of the per-test reset that
 * tests/Concerns/InteractsWithDatabase::resetMysqlDatabase() runs for the
 * PHPUnit Feature suite — the Playwright specs call it before every test
 * (tests/E2E/support/db.mjs) so each browser test starts from the same clean
 * state the Feature tests get, rather than accumulating rows across the run.
 *
 * Connection details come from the DB_* environment variables (same as
 * seed-test-db.php); AUTO_INCREMENT resets to 1, so the reseeded admin is
 * always user_id 1 and the session written by global-setup.js stays valid.
 *
 * Usage:
 *   DB_HOSTNAME=127.0.0.1 php tests/Support/reset-test-db.php
 */
$host = getenv('DB_HOSTNAME') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$name = getenv('DB_DATABASE') ?: 'invoiceplane_test';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: 'root';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8', $host, $port, $name),
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

// A test that changed nothing leaves the database exactly as the last reseed produced it, so the next reset has
// nothing to do. "Exactly" is checked, not assumed: the content checksum and AUTO_INCREMENT counter of every table are
// compared with those taken right after the last reseed.
$signatureFile = sys_get_temp_dir() . '/ip_e2e_baseline_' . md5($host . $port . $name) . '.sig';
$signature     = static function (PDO $pdo): string {
    $names = $pdo
        ->query('SELECT table_name, auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')
        ->fetchAll(PDO::FETCH_NUM);
    $list  = implode(',', array_map(static fn (array $row): string => '`' . str_replace('`', '``', (string) $row[0]) . '`', $names));
    $parts = [];
    foreach ($pdo->query('CHECKSUM TABLE ' . $list)->fetchAll(PDO::FETCH_NUM) as $row) {
        $parts[] = $row[0] . '=' . $row[1];
    }
    foreach ($names as $row) {
        $parts[] = $row[0] . '#' . ($row[1] ?? '');
    }

    return md5(implode('|', $parts));
};
if (is_file($signatureFile) && hash_equals((string) file_get_contents($signatureFile), $signature($pdo))) {
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
// Only a table that holds rows or whose AUTO_INCREMENT counter has moved can differ from a clean one; truncating the
// other tables is a DDL statement that does nothing, and it was about half of the reset.
$tables = $pdo
    ->query('SELECT table_name, auto_increment FROM information_schema.tables WHERE table_schema = DATABASE()')
    ->fetchAll(PDO::FETCH_NUM);
foreach ($tables as [$table, $autoIncrement]) {
    $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
    $dirty  = ($autoIncrement !== null && (int) $autoIncrement > 1)
        || (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM {$quoted})")->fetchColumn();
    if ($dirty) {
        $pdo->exec('TRUNCATE TABLE ' . $quoted);
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

require_once __DIR__ . '/seed_baseline.php';
ip_seed_baseline($pdo);

// Settings that individual PHPUnit Feature tests add in their own setUp() but
// that the E2E suite needs present for every browser flow that touches quotes
// or invoices (Mdl_Quotes::get_date_due() etc. build a DateInterval straight
// from these — a missing value throws "Unknown or bad format").
$e2eSettings = [
    'quotes_expire_after'   => '15',
    'invoices_due_after'    => '30',
    'default_invoice_group' => '1',
    'default_quote_group'   => '1',
    // So a freshly created draft quote/invoice gets a visible number rather
    // than an empty string (matches a normal InvoicePlane install).
    'generate_quote_number_for_draft'   => '1',
    'generate_invoice_number_for_draft' => '1',
    // Let the mailer render its send form (rather than the "not configured"
    // stub) — no mail is actually sent from the E2E server.
    'email_send_method' => 'phpmail',
];
$upsert = $pdo->prepare(
    'INSERT INTO `ip_settings` (`setting_key`, `setting_value`) VALUES (:k, :v)'
    . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)'
);
foreach ($e2eSettings as $key => $value) {
    $upsert->execute([':k' => $key, ':v' => $value]);
}

file_put_contents($signatureFile, $signature($pdo));
