<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A hollow test passes whatever the production code does: it asserts nothing, asserts `true`, or exercises a copy of the
 * logic that lives in the test file. Those tests are marked `#[Group('hollow')]` and listed, with the reason, in
 * tests/Support/hollow-tests.json, so a rebuild (the Laravel port) knows not to carry them over as if they proved anything.
 *
 * This guard keeps the marker and the manifest in step, so neither can drift on its own.
 */
final class HollowTestManifestTest extends TestCase
{
    private const MARKER = '/#\[\s*(?:\\\\PHPUnit\\\\Framework\\\\Attributes\\\\)?Group\(\s*[\'"]hollow[\'"]\s*\)\s*\]/';

    /**
     * @return array<string, string> "relative/File.php::method" => reason
     */
    private static function manifest(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Support/hollow-tests.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function manifestEntries(): array
    {
        $cases = [];

        foreach (self::manifest() as $key => $reason) {
            [$file, $method] = explode('::', $key, 2);
            $cases[$key]     = [$file, $method, $reason];
        }

        self::assertNotEmpty($cases, 'The hollow-test manifest is empty; delete the manifest and this guard together, not just the entries.');

        return $cases;
    }

    /**
     * The `#[Group('hollow')]` marker of every test method found under tests/, as "relative/File.php::method".
     *
     * @return list<string>
     */
    private function markedMethods(): array
    {
        $root   = dirname(__DIR__, 3);
        $marked = [];
        $files  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/tests', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if ( ! preg_match_all('/((?:[ \t]*#\[[^\n]*\]\n)+)[ \t]*public function (\w+)\(/', $source, $methods, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($methods as [, $attributes, $name]) {
                if (preg_match(self::MARKER, $attributes) === 1) {
                    $marked[] = substr($file->getPathname(), strlen($root) + 1) . '::' . $name;
                }
            }
        }

        return $marked;
    }

    #[Test]
    #[DataProvider('manifestEntries')]
    public function it_lists_only_test_methods_that_carry_the_hollow_marker(string $file, string $method, string $reason): void
    {
        /* Arrange */
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);

        /* Act */
        $found = preg_match('/((?:[ \t]*#\[[^\n]*\]\n)+)[ \t]*public function ' . preg_quote($method, '/') . '\(/', $source, $match);

        /* Assert */
        self::assertSame(1, $found, "{$file}::{$method} is listed as hollow but no such method exists.");
        self::assertMatchesRegularExpression('/#\[[^\n]*Test\]/', $match[1], "{$file}::{$method} is not a #[Test] method.");
        self::assertMatchesRegularExpression(self::MARKER, $match[1], "{$file}::{$method} is listed as hollow but is not marked #[Group('hollow')].");
        self::assertGreaterThanOrEqual(30, strlen($reason), "{$file}::{$method} needs a reason of at least a sentence.");
    }

    #[Test]
    public function it_lists_every_method_that_carries_the_hollow_marker(): void
    {
        /* Arrange */
        $listed = array_keys(self::manifest());

        /* Act */
        $unlisted = array_values(array_diff($this->markedMethods(), $listed));

        /* Assert */
        self::assertSame([], $unlisted, 'These methods are marked #[Group(\'hollow\')] but missing from tests/Support/hollow-tests.json.');
    }
}
