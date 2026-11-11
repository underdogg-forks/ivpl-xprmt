<?php

declare(strict_types=1);

namespace Tests\Support\SecurityScanner;

/**
 * One place the scanner wants a person to look at.
 *
 * The fingerprint names the rule, the file, the function and the code itself, but not the line number, so it
 * stays the same when unrelated lines are added above it.
 */
final class Finding
{
    public function __construct(
        public readonly string $rule,
        public readonly string $file,
        public readonly int $line,
        public readonly string $where,
        public readonly string $message,
        public readonly string $fingerprint,
    ) {}
}
