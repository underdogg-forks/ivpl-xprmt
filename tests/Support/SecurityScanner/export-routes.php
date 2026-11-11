<?php

/**
 * Writes the route list of a checkout as JSON, in the shape of `php artisan route:list --json`.
 * Usage: php tests/Support/SecurityScanner/export-routes.php [folder] > routes.json.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Tests\Support\SecurityScanner\RouteInventory;

echo json_encode((new RouteInventory(rtrim($argv[1] ?? dirname(__DIR__, 3), '/')))->routes(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
