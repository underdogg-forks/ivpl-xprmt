<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Catches two classes of accidental commit that otherwise ship silently:
 *
 * 1. A file pushed with placeholder text instead of real content — e.g. an
 *    automated tool call that sent a literal sentinel string ("__FILE_CONTENT__")
 *    as the body of a file instead of the file's actual contents.
 * 2. An unresolved git merge leaving `<<<<<<<` / `=======` / `>>>>>>>` conflict
 *    markers committed to a tracked file.
 *
 * Both are invisible in a diff review that only checks "did the intended lines
 * change" and not "is the resulting file actually valid" — this test reads
 * every tracked text file fresh from disk and checks that directly.
 */
final class RepositoryIntegrityTest extends TestCase
{
    /** Built by concatenation so this file's own source never contains the literal marker. */
    private const FORBIDDEN_SENTINELS = [
        '__' . 'FILE_CONTENT' . '__',
        '__' . 'PLACEHOLDER' . '__',
        'PLACEHOLDER_CONTENT_HERE',
        'TODO_INSERT_CONTENT_HERE',
    ];

    private const TRACKED_TEXT_EXTENSIONS = ['php', 'md', 'json', 'yml', 'yaml', 'xml', 'js', 'ts', 'sql'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function trackedTextFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $self = __FILE__;

        $output   = [];
        $exitCode = 0;
        exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $output, $exitCode);

        $cases = [];

        foreach ($output as $relativePath) {
            $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));

            if ( ! in_array($extension, self::TRACKED_TEXT_EXTENSIONS, true)) {
                continue;
            }

            $absolute = $root . '/' . $relativePath;

            // This test's own source necessarily documents the sentinel strings and
            // the conflict-marker characters; it can never check itself.
            if ($absolute === $self) {
                continue;
            }

            if ( ! is_file($absolute)) {
                continue;
            }

            $cases[$relativePath] = [$absolute];
        }

        self::assertNotEmpty($cases, 'git ls-files returned nothing; are we running outside a git checkout?');

        return $cases;
    }

    #[Test]
    #[DataProvider('trackedTextFiles')]
    public function it_has_no_placeholder_sentinel_in_a_tracked_file(string $path): void
    {
        $content = file_get_contents($path);
        self::assertIsString($content, "Could not read {$path}.");

        foreach (self::FORBIDDEN_SENTINELS as $sentinel) {
            self::assertStringNotContainsString(
                $sentinel,
                $content,
                "{$path} contains the placeholder sentinel \"{$sentinel}\" instead of real content — "
                . 'a file was very likely pushed with literal placeholder text.'
            );
        }
    }

    #[Test]
    #[DataProvider('trackedTextFiles')]
    public function it_has_no_unresolved_merge_conflict_markers_in_a_tracked_file(string $path): void
    {
        $content = file_get_contents($path);
        self::assertIsString($content, "Could not read {$path}.");

        $markerStart = str_repeat('<', 7);
        $markerMid   = str_repeat('=', 7);
        $markerEnd   = str_repeat('>', 7);

        $pattern = '/^(' . preg_quote($markerStart, '/') . ' |' . preg_quote($markerMid, '/') . '$|'
            . preg_quote($markerEnd, '/') . ' )/m';

        self::assertDoesNotMatchRegularExpression(
            $pattern,
            $content,
            "{$path} contains an unresolved git merge conflict marker."
        );
    }
}
