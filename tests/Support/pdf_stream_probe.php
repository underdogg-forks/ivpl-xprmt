<?php

/**
 * Runs pdf_create() in a clean process and reports what it streamed and what it wrote to disk.
 * pdf_create() ends in mPDF's Output('I'), which emits headers and the PDF body directly, so it
 * can only be observed from outside the process. Argument: base64(json) of
 * {root, filename, stream, isInvoice, isGuest, seed}. Prints one line of JSON.
 */
$args = json_decode((string) base64_decode($argv[1] ?? '', true), true, 512, JSON_THROW_ON_ERROR);
$root = rtrim($args['root'], '/') . '/';

define('BASEPATH', $root);
define('IP_DEBUG', false);
define('UPLOADS_ARCHIVE_FOLDER', $root . 'archive/');
define('UPLOADS_TEMP_FOLDER', $root . 'temp/');
define('UPLOADS_TEMP_MPDF_FOLDER', $root . 'temp/');

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
require $repo . '/tests/Support/FakeCiSettings.php';
require $repo . '/tests/Support/UnitCodeIgniter.php';
require $repo . '/application/helpers/echo_helper.php';
require $repo . '/application/helpers/settings_helper.php';
if ( ! function_exists('trans')) {
    function trans(string $line, string $id = '', $default = null)
    {
        return $line === 'page' ? 'Page' : $line;
    }
}

require $repo . '/application/helpers/mpdf_helper.php';

$settings        = new Tests\Support\FakeCiSettings();
$settings->_data    = $args['settings'] ?? [];
$settings->settings = $args['settings'] ?? [];

$GLOBALS['unitCiInstance'] = (object) ['mdl_settings' => $settings];

foreach ($args['seed'] ?? [] as $seeded) {
    $seedName = is_array($seeded) ? $seeded['name'] : $seeded;
    file_put_contents(UPLOADS_ARCHIVE_FOLDER . $seedName, '%PDF-seeded');

    if (is_array($seeded) && isset($seeded['mtime'])) {
        touch(UPLOADS_ARCHIVE_FOLDER . $seedName, (int) $seeded['mtime']);
    }
}

ob_start();
$returned = pdf_create(
    '<p>Invoice body</p>',
    $args['filename'],
    $args['stream'],
    null,
    $args['isInvoice'] ?? null,
    $args['isGuest'] ?? null
);
$streamed = (string) ob_get_clean();

// Text-showing operators (Tj / TJ) in the page content streams of the streamed PDF: a footer line adds some.
$textOperators = static function (string $pdf): int {
    $count = 0;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams) > 0) {
        foreach ($streams[1] as $stream) {
            $plain = @gzuncompress($stream);
            $plain = $plain === false ? $stream : $plain;
            $count += preg_match_all('/\]\s*TJ|\)\s*Tj/', $plain);
        }
    }

    return $count;
};

$list = static fn (string $dir): array => array_values(array_diff(scandir($dir) ?: [], ['.', '..']));

echo json_encode([
    'returned'    => is_string($returned) ? str_replace($root, '', $returned) : null,
    'stream_head' => substr($streamed, 0, 5),
    'stream_len'  => strlen($streamed),
    'text_ops'    => $textOperators($streamed),
    'archive'     => $list(UPLOADS_ARCHIVE_FOLDER),
    'temp'        => $list(UPLOADS_TEMP_FOLDER),
], JSON_THROW_ON_ERROR);
