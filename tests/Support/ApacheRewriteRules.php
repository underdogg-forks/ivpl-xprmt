<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Evaluates the mod_rewrite rules of the htaccess template for one request path, the way Apache does in
 * per-directory context: the pattern is matched against the path without its leading slash, rules run in file
 * order, and the first rule that matches (and whose conditions hold) with an L flag ends the run.
 *
 * Only what the shipped htaccess uses is supported: RewriteRule with the flags L, NC, F and R=<status>, a "-"
 * substitution, and RewriteCond on %{REQUEST_FILENAME} with !-f / !-d. Commented lines are ignored.
 */
final class ApacheRewriteRules
{
    public const REFUSED = 'refused';

    public const FRONT_CONTROLLER = 'front controller';

    public const AS_IS = 'as is';

    /** @param list<array{pattern: string, substitution: string, flags: list<string>, conditions: list<string>}> $rules */
    private function __construct(private readonly array $rules) {}

    public static function fromHtaccess(string $htaccess): self
    {
        $rules      = [];
        $conditions = [];

        foreach (preg_split('/\R/', $htaccess) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^RewriteCond\s+(\S+)\s+(\S+)/', $line, $match) === 1) {
                $conditions[] = $match[1] . ' ' . $match[2];

                continue;
            }

            if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?\s*$/', $line, $match) === 1) {
                $rules[] = [
                    'pattern'      => $match[1],
                    'substitution' => $match[2],
                    'flags'        => array_values(array_filter(array_map('trim', explode(',', $match[3] ?? '')))),
                    'conditions'   => $conditions,
                ];
                $conditions = [];
            }
        }

        return new self($rules);
    }

    /** @return list<array{pattern: string, substitution: string, flags: list<string>, conditions: list<string>}> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** The same rules without the ones whose pattern contains $needle (to prove a single rule is the one at work). */
    public function without(string $needle): self
    {
        return new self(array_values(array_filter(
            $this->rules,
            static fn (array $rule): bool => ! str_contains($rule['pattern'], $needle)
        )));
    }

    /**
     * @param bool $exists whether the path is an existing file or folder (decides the !-f / !-d conditions)
     *
     * @return self::REFUSED|self::FRONT_CONTROLLER|self::AS_IS
     */
    public function outcome(string $path, bool $exists = true): string
    {
        $subject = ltrim($path, '/');

        foreach ($this->rules as $rule) {
            $regex = '#' . str_replace('#', '\#', $rule['pattern']) . '#' . (in_array('NC', $rule['flags'], true) ? 'i' : '');

            if (preg_match($regex, $subject) !== 1 || ! $this->conditionsHold($rule['conditions'], $exists)) {
                continue;
            }

            foreach ($rule['flags'] as $flag) {
                if ($flag === 'F' || preg_match('/^R=(403|404|410)$/', $flag) === 1) {
                    return self::REFUSED;
                }
            }

            if ($rule['substitution'] === '-') {
                return self::AS_IS;
            }

            return str_contains($rule['substitution'], 'index.php') ? self::FRONT_CONTROLLER : self::AS_IS;
        }

        return self::AS_IS;
    }

    /** @param list<string> $conditions */
    private function conditionsHold(array $conditions, bool $exists): bool
    {
        foreach ($conditions as $condition) {
            if (in_array($condition, ['%{REQUEST_FILENAME} !-f', '%{REQUEST_FILENAME} !-d'], true)) {
                if ($exists) {
                    return false;
                }

                continue;
            }

            throw new RuntimeException('Unsupported RewriteCond in the htaccess template: ' . $condition);
        }

        return true;
    }
}
