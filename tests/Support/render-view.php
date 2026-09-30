<?php

/**
 * Renders one application view in an isolated process with a minimal set of function seams, so a
 * test can look at the exact HTML a browser would receive without booting CodeIgniter.
 *
 * Usage: php render-view.php <view path relative to repo root> <base64(json({vars, settings}))>
 * Prints the rendered HTML.
 */
$root = dirname(__DIR__, 2);

define('BASEPATH', $root . '/vendor/pocketarc/codeigniter/system/');

$payload  = json_decode(base64_decode($argv[2] ?? '', true) ?: '{}', true);
$settings = $payload['settings'] ?? [];
$vars     = $payload['vars'] ?? [];

require $root . '/application/helpers/echo_helper.php';

function trans($key, $id = '', $default = null)
{
    return $key;
}

function get_setting($key, $default = '', $escape = false)
{
    $value = $GLOBALS['settings'][$key] ?? $default;

    return $escape ? htmlsc($value) : $value;
}

function format_amount($amount = null)
{
    return $amount ? number_format((float) $amount, 2, '.', ',') : null;
}

(function (string $__file, array $__vars): void {
    // Views receive their data as objects (e.g. $invoice->invoice_discount_amount).
    foreach ($__vars as $k => $v) {
        $__vars[$k] = is_array($v) ? (object) $v : $v;
    }
    extract($__vars, EXTR_SKIP);
    include $__file;
})($root . '/' . ltrim($argv[1] ?? '', '/'), $vars);
