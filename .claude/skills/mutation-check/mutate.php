#!/usr/bin/env php
<?php

/**
 * Mutation check for one PHP production file.
 *
 *   php .claude/skills/mutation-check/mutate.php --file=<path> --tests=<phpunit args> [options]
 *
 * Breaks the file one small way at a time (flipped comparison, swapped && / ||, negation removed,
 * constant changed, `if` forced true/false, statement deleted), runs the tests, and reports which
 * mutants the tests caught (KILLED) and which they did not (SURVIVED). A survivor is either an
 * equivalent mutation or a missing assertion; the SKILL.md explains how to tell.
 *
 * The file is always restored (shutdown handler, signal handler, final hash check).
 */
const EXIT_SURVIVORS = 1;
const EXIT_USAGE     = 2;
const EXIT_BASELINE  = 3;
const EXIT_INFRA     = 4;

$opt = getopt('', ['file:', 'tests:', 'lines:', 'diff:', 'max:', 'timeout:', 'cmd:', 'seed:', 'json:', 'list', 'allow-dirty', 'help']);

if (isset($opt['help']) || empty($opt['file']) || (empty($opt['tests']) && empty($opt['cmd']) && ! isset($opt['list']))) {
    fwrite(STDERR, <<<'TXT'
Usage: php .claude/skills/mutation-check/mutate.php --file=PATH --tests="PHPUNIT ARGS" [options]
  --file=PATH       production file to mutate (required)
  --tests=ARGS      phpunit paths/filters, e.g. "tests/Unit/Foo/BarTest.php" or "--filter it_does_x tests/Feature"
  --lines=A-B       only mutate lines A..B (repeatable as A-B,C-D)
  --diff=REF        only mutate lines changed against git REF (e.g. origin/prep/v180)
  --max=N           cap the number of mutants (seeded sample, default 40; 0 = all)
  --timeout=SEC     per-mutant test timeout (default 300)
  --cmd=CMD         full test command; {tests} is replaced by --tests (default: sandbox phpunit)
  --seed=N          sampling seed (default 1)
  --json=PATH       also write the result as JSON
  --list            list the mutants without running anything
  --allow-dirty     mutate a file that has uncommitted changes (they are restored byte for byte)
Exit codes: 0 all killed, 1 survivors, 2 usage, 3 baseline red, 4 infrastructure problem.

TXT);
    exit(EXIT_USAGE);
}

$root = trim((string) shell_exec('git rev-parse --show-toplevel 2>/dev/null')) ?: getcwd();
chdir($root);

$file = $opt['file'];
if ( ! is_file($file) || ! str_ends_with($file, '.php')) {
    fwrite(STDERR, "Not a PHP file: {$file}\n");
    exit(EXIT_USAGE);
}
$original = (string) file_get_contents($file);
$hash     = sha1($original);

if ( ! isset($opt['list']) && ! isset($opt['allow-dirty'])) {
    exec('git diff --quiet -- ' . escapeshellarg($file), $o, $dirty);
    if ($dirty !== 0) {
        fwrite(STDERR, "{$file} has uncommitted changes. Commit or stash them, or pass --allow-dirty.\n");
        exit(EXIT_USAGE);
    }
}

// ---- mutant discovery -------------------------------------------------------------------------

$allowedLines = null;
if (isset($opt['lines'])) {
    $allowedLines = [];
    foreach (explode(',', $opt['lines']) as $range) {
        [$a, $b] = array_pad(explode('-', $range), 2, null);
        foreach (range((int) $a, (int) ($b ?? $a)) as $n) {
            $allowedLines[$n] = true;
        }
    }
}
if (isset($opt['diff'])) {
    $allowedLines ??= [];
    $diff = (string) shell_exec('git diff -U0 ' . escapeshellarg($opt['diff']) . ' -- ' . escapeshellarg($file));
    preg_match_all('/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/m', $diff, $m, PREG_SET_ORDER);
    foreach ($m as $h) {
        for ($n = (int) $h[1]; $n < (int) $h[1] + (int) ($h[2] === '' ? 1 : $h[2]); $n++) {
            $allowedLines[$n] = true;
        }
    }
}

$guardLines = [];
foreach (explode("\n", $original) as $n => $src) {
    if (str_contains($src, "defined('BASEPATH')")) {
        $guardLines[$n + 1] = true;
    }
}
$tokens  = token_get_all($original);
$mutants = [];
$swap    = [
    T_IS_IDENTICAL        => '!==', T_IS_NOT_IDENTICAL => '===', T_IS_EQUAL => '!=', T_IS_NOT_EQUAL => '==',
    T_IS_GREATER_OR_EQUAL => '<', T_IS_SMALLER_OR_EQUAL => '>',
    T_BOOLEAN_AND         => '||', T_BOOLEAN_OR => '&&',
];
$char = ['>' => '<=', '<' => '>=', '!' => '', '+' => '-', '-' => '+', '*' => '/'];

$line = 1;
foreach ($tokens as $i => $t) {
    $text = is_array($t) ? $t[1] : $t;
    $id   = is_array($t) ? $t[0] : null;
    $at   = is_array($t) ? $t[2] : $line;
    $line = $at + substr_count($text, "\n");

    if (($allowedLines !== null && ! isset($allowedLines[$at])) || isset($guardLines[$at])) {
        continue;
    }
    if ($id !== null && isset($swap[$id])) {
        $mutants[] = ['line' => $at, 'op' => 'operator', 'from' => $text, 'to' => $swap[$id], 'edits' => [$i => $swap[$id]]];
    } elseif ($id === null && isset($char[$text])) {
        $prev    = $tokens[$i - 1] ?? null;
        $isUnary = in_array($text, ['-', '+'], true) && ( ! is_array($prev) ? in_array($prev, ['(', ',', '=', '[', '?', ':'], true) : $prev[0] === T_DOUBLE_ARROW);
        if ($isUnary) {
            continue;
        }
        $mutants[] = ['line' => $at, 'op' => $text === '!' ? 'negation-removed' : 'operator', 'from' => $text, 'to' => $char[$text] === '' ? '(removed)' : $char[$text], 'edits' => [$i => $char[$text]]];
    } elseif ($id === T_STRING && in_array(strtolower($text), ['true', 'false'], true)) {
        $to        = strtolower($text) === 'true' ? 'false' : 'true';
        $mutants[] = ['line' => $at, 'op' => 'boolean', 'from' => $text, 'to' => $to, 'edits' => [$i => $to]];
    } elseif ($id === T_LNUMBER) {
        $to        = (string) ((int) $text === 0 ? 1 : (int) $text + 1);
        $mutants[] = ['line' => $at, 'op' => 'number', 'from' => $text, 'to' => $to, 'edits' => [$i => $to]];
    } elseif ($id === T_IF || $id === T_ELSEIF) {
        $open = $i + 1;
        while (isset($tokens[$open]) && $tokens[$open] !== '(') {
            $open++;
        }
        $depth = 0;
        $close = $open;
        for ($k = $open; isset($tokens[$k]); $k++) {
            $depth += $tokens[$k] === '(' ? 1 : ($tokens[$k] === ')' ? -1 : 0);
            if ($depth === 0) {
                $close = $k;
                break;
            }
        }
        foreach (['true', 'false'] as $force) {
            $edits = [$open + 1 => $force];
            for ($k = $open + 2; $k < $close; $k++) {
                $edits[$k] = '';
            }
            $mutants[] = ['line' => $at, 'op' => 'condition-forced', 'from' => 'if (...)', 'to' => "if ({$force})", 'edits' => $edits];
        }
    }
}

// statement deletion: single-line "$this->call(...);" statements
foreach (explode("\n", $original) as $n => $src) {
    $no = $n + 1;
    if ($allowedLines !== null && ! isset($allowedLines[$no])) {
        continue;
    }
    if (preg_match('/^\s*\$this->[^=;]*\(.*\);\s*$/', $src) === 1 && substr_count($src, '(') === substr_count($src, ')') && ! str_contains($src, 'return')) {
        $mutants[] = ['line' => $no, 'op' => 'statement-deleted', 'from' => trim($src), 'to' => '(deleted)', 'delete_line' => $no];
    }
}

usort($mutants, fn ($a, $b) => [$a['line'], $a['op']] <=> [$b['line'], $b['op']]);
$max = isset($opt['max']) ? (int) $opt['max'] : 40;
if ($max > 0 && count($mutants) > $max) {
    mt_srand((int) ($opt['seed'] ?? 1));
    $keys = array_keys($mutants);
    shuffle($keys);
    $keys = array_slice($keys, 0, $max);
    sort($keys);
    $mutants = array_values(array_intersect_key($mutants, array_flip($keys)));
}

$render = function (array $mutant) use ($tokens, $original): string {
    if (isset($mutant['delete_line'])) {
        $lines                             = explode("\n", $original);
        $lines[$mutant['delete_line'] - 1] = '';

        return implode("\n", $lines);
    }
    $out = '';
    foreach ($tokens as $i => $t) {
        $out .= array_key_exists($i, $mutant['edits']) ? $mutant['edits'][$i] : (is_array($t) ? $t[1] : $t);
    }

    return $out;
};

if (isset($opt['list'])) {
    foreach ($mutants as $m) {
        printf("%s:%d  %-17s %s  ->  %s\n", $file, $m['line'], $m['op'], $m['from'], $m['to']);
    }
    printf("%d mutants\n", count($mutants));
    exit(0);
}

// ---- safe execution ---------------------------------------------------------------------------

$restore = function () use ($file, $original): void {
    if (file_exists($file) && sha1((string) file_get_contents($file)) !== sha1($original)) {
        file_put_contents($file, $original);
    }
};
register_shutdown_function($restore);
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM] as $sig) {
        pcntl_signal($sig, function () use ($restore) {
            $restore();
            exit(130);
        });
    }
}

exec('ps -eo args', $procs);
foreach ($procs as $p) {
    if (str_contains($p, 'bin/phpunit') && ! str_contains($p, 'mutate.php') && ! str_contains($p, 'grep')) {
        fwrite(STDERR, "Another phpunit run is active ({$p}). Runs share one test database: wait for it.\n");
        exit(EXIT_INFRA);
    }
}

$timeout = (int) ($opt['timeout'] ?? 300);
$default = 'env -u DB_HOSTNAME -u DB_PORT -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD '
    . 'php .sandbox-tools/punit/vendor/bin/phpunit --bootstrap tests/bootstrap.php --no-coverage --stop-on-failure {tests}';
$cmd = 'timeout ' . $timeout . ' ' . str_replace('{tests}', $opt['tests'] ?? '', $opt['cmd'] ?? $default) . ' 2>&1';

$run = function () use ($cmd): array {
    $out = [];
    exec($cmd, $out, $code);

    return [$code, implode("\n", $out)];
};
$infra = fn (string $out): bool => preg_match('/unreachable|Connection refused|Unable to connect to the database|Access denied for user/i', $out) === 1;

fwrite(STDERR, "Baseline run...\n");
[$code, $out] = $run();
if ($code !== 0) {
    fwrite(STDERR, ($infra($out) ? "Database problem (run tests/Support/sandbox-mariadb.sh):\n" : "Baseline is not green; fix the tests first:\n") . substr($out, -1500) . "\n");
    exit($infra($out) ? EXIT_INFRA : EXIT_BASELINE);
}

$results = [];
foreach ($mutants as $n => $m) {
    file_put_contents($file, $render($m));
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lint, $lintCode);
    $lint = [];
    if ($lintCode !== 0) {
        $status = 'INVALID';
    } else {
        [$code, $out] = $run();
        if ($infra($out)) {
            $restore();
            fwrite(STDERR, "Infrastructure failure during mutant {$n}; aborting.\n" . substr($out, -800) . "\n");
            exit(EXIT_INFRA);
        }
        $status = $code === 0 ? 'SURVIVED' : ($code === 124 ? 'TIMEOUT' : 'KILLED');
    }
    $restore();
    $results[] = $m + ['status' => $status];
    printf("%-9s %s:%d  %-17s %s  ->  %s\n", $status, $file, $m['line'], $m['op'], $m['from'], $m['to']);
}

$restore();
if (sha1((string) file_get_contents($file)) !== $hash) {
    fwrite(STDERR, "FATAL: {$file} was not restored. Run: git checkout -- {$file}\n");
    exit(EXIT_INFRA);
}

$count = array_count_values(array_column($results, 'status'));
$kill  = ($count['KILLED'] ?? 0) + ($count['TIMEOUT'] ?? 0);
$valid = $kill + ($count['SURVIVED'] ?? 0);
printf("\n%d mutants: %d killed, %d survived, %d invalid, %d timeout. Score %s\n", count($results), $count['KILLED'] ?? 0, $count['SURVIVED'] ?? 0, $count['INVALID'] ?? 0, $count['TIMEOUT'] ?? 0, $valid ? round(100 * $kill / $valid) . '%' : 'n/a');
if (isset($opt['json'])) {
    file_put_contents($opt['json'], json_encode(['file' => $file, 'results' => array_map(fn ($r) => array_diff_key($r, ['edits' => 1]), $results)], JSON_PRETTY_PRINT));
}
exit(($count['SURVIVED'] ?? 0) > 0 ? EXIT_SURVIVORS : 0);
