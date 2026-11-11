<?php

declare(strict_types=1);

/**
 * Command-line entry of the security class scanner.
 *
 *   php tests/Support/SecurityScanner/scan.php [project folder] [--rule=<name>] [--summary] [--fingerprints]
 *
 * Without a folder it scans the project this file is in. --summary prints one count per rule, --fingerprints
 * prints the fingerprints only (the format of the baseline file).
 */

use Tests\Support\SecurityScanner\Scanner;

$project = dirname(__DIR__, 3);

require_once $project . '/vendor/autoload.php';
require_once __DIR__ . '/Finding.php';
require_once __DIR__ . '/Scanner.php';

$target  = $project;
$rule    = null;
$summary = false;
$prints  = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--rule=')) {
        $rule = substr($argument, 7);
    } elseif ($argument === '--summary') {
        $summary = true;
    } elseif ($argument === '--fingerprints') {
        $prints = true;
    } else {
        $target = rtrim($argument, '/');
    }
}

$findings = (new Scanner($target))->scan();

if ($rule !== null) {
    $findings = array_values(array_filter($findings, static fn ($finding): bool => $finding->rule === $rule));
}

if ($prints) {
    foreach ($findings as $finding) {
        echo $finding->fingerprint, PHP_EOL;
    }

    exit(0);
}

$perRule = [];

foreach ($findings as $finding) {
    $perRule[$finding->rule] = ($perRule[$finding->rule] ?? 0) + 1;

    if ( ! $summary) {
        printf("%s  %s:%d  [%s]\n    %s\n", $finding->rule, $finding->file, $finding->line, $finding->where, mb_strimwidth($finding->message, 0, 260, '...'));
    }
}

ksort($perRule);
echo PHP_EOL;

foreach ($perRule as $name => $count) {
    printf("%5d  %s\n", $count, $name);
}

printf("%5d  total\n", count($findings));
