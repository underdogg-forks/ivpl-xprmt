<?php

/**
 * Runs Validator::create_error_text() in an isolated process, so the test needs neither a booted
 * CodeIgniter nor global stubs that would leak into other tests.
 *
 * Usage: php validator-error-text.php <base64(json(errors))>   -> prints json {"out": "<html>"}
 */
$root = dirname(__DIR__, 2);

define('BASEPATH', $root . '/vendor/pocketarc/codeigniter/system/');
define('APPPATH', $root . '/application/');

class MY_Model {}

function lang(string $key): string
{
    return ['validator_fail' => 'Unable to process field %s: %s'][$key] ?? $key;
}

require $root . '/application/helpers/echo_helper.php';
require $root . '/application/core/Validator.php';

$errors    = json_decode(base64_decode($argv[1] ?? '', true) ?: '[]', true);
$validator = (new ReflectionClass('Validator'))->newInstanceWithoutConstructor();

echo json_encode(['out' => $validator->create_error_text($errors)]);
