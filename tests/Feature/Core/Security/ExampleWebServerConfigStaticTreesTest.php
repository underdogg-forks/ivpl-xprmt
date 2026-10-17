<?php

namespace Tests\Feature\Core\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The example nginx/Apache configs use public/ as the web root, and public/ only holds index.php.
 * The CSS/JS under assets/ and the logo files under uploads/ are outside it, so without an explicit
 * mapping the application loads unstyled and without its logo. Each static tree the pages link to
 * has to be mapped, and the sensitive uploads subdirectories must stay denied.
 */
#[Group('security')]
final class ExampleWebServerConfigStaticTreesTest extends TestCase
{
    #[Test]
    public function it_maps_the_assets_tree_into_the_nginx_public_root(): void
    {
        /* Arrange */
        $conf = (string) file_get_contents(ROOT_PATH . '/resources/docker/nginx/invoiceplane.conf');

        /* Act */
        $mapped = preg_match('#location\s+\^~\s+/assets/\s*\{[^}]*alias\s+\S+/assets/;#', $conf) === 1;

        /* Assert */
        self::assertTrue($mapped, 'nginx must alias /assets/ because public/ does not contain it.');
        self::assertDirectoryExists(ROOT_PATH . '/assets');
        self::assertFileDoesNotExist(ROOT_PATH . '/public/assets', 'if assets/ ever moves under public/, drop the alias and this test.');
    }

    #[Test]
    public function it_serves_only_image_files_directly_inside_uploads_and_keeps_the_private_subdirectories_denied(): void
    {
        /* Arrange */
        $conf = (string) file_get_contents(ROOT_PATH . '/resources/docker/nginx/invoiceplane.conf');

        /* Act */
        preg_match('#location\s+~\s+\^/uploads/\(\[\^/\]\+\\\\\.\(\?:([^)]*)\)\)\$\s*\{[^}]*alias#', $conf, $m);
        $extensions = isset($m[1]) ? explode('|', $m[1]) : [];

        /* Assert */
        self::assertNotSame([], $extensions, 'nginx must map the logo files directly inside uploads/.');
        self::assertSame([], array_intersect($extensions, ['php', 'html', 'csv', 'xml', 'pdf']), 'only image extensions may be served from uploads/.');
        foreach (['archive', 'customer_files', 'import', 'temp'] as $private) {
            self::assertMatchesRegularExpression('#location\s+\^~\s+/uploads/' . $private . '/\s*\{\s*deny all;#', $conf, "uploads/{$private} must stay denied.");
        }
    }

    #[Test]
    public function it_maps_the_same_static_trees_in_the_apache_example(): void
    {
        /* Arrange */
        $conf = (string) file_get_contents(ROOT_PATH . '/resources/docker/apache/invoiceplane.conf');

        /* Act / Assert */
        self::assertMatchesRegularExpression('#^\s*Alias\s+/assets\s+\S+/assets\s*$#m', $conf);
        self::assertMatchesRegularExpression('#AliasMatch\s+"\^/uploads/\(\[\^/\]\+\\\\\.\(\?:png\|jpe\?g\|gif\|svg\|webp\)\)\$"#', $conf);
        foreach (['archive', 'customer_files', 'import', 'temp'] as $private) {
            self::assertMatchesRegularExpression('#<Directory\s+\S+/uploads/' . $private . '>\s*Require all denied#', $conf);
        }
    }
}
