<?php

namespace Tests\Unit\Core;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * On 1.7.2 (mPDF 8.3.1) a PDF lost all its template styling whenever PHP could not fetch the site's own
 * stylesheet URL over HTTPS: the default templates link their CSS with absolute base_url() URLs, mPDF fetches
 * them remotely, and its fallback to the local file is inverted (mpdf/mpdf CssLoader). pdf_inline_local_stylesheets()
 * reads the stylesheets of this installation from disk instead, so no network round trip is needed.
 */
#[Group('pdf')]
final class PdfInlineLocalStylesheetsTest extends TestCase
{
    private const BASE = 'https://invoices.example.com/';

    private string $root;

    private string $assets;

    protected function setUp(): void
    {
        require_once APPPATH . 'helpers/mpdf_helper.php';

        $this->root   = sys_get_temp_dir() . '/ip-pdf-css-' . bin2hex(random_bytes(6));
        $this->assets = $this->root . '/assets';
        mkdir($this->assets . '/invoiceplane/css', 0o775, true);
        mkdir($this->assets . '/core/css', 0o775, true);
        file_put_contents($this->assets . '/invoiceplane/css/templates.css', "body { font-family: freemono; }\ntable { width: 100%; }");
        file_put_contents($this->assets . '/core/css/custom-pdf.css', '/* custom */ p { color: red; }');
        file_put_contents($this->assets . '/invoiceplane/css/with-urls.css', 'a{background:url(pixel.png)} b{background:url("../img/b.png")} i{background:url(\'img/i.png\')} u{background:url( spaced.png )}');
        file_put_contents($this->assets . '/invoiceplane/css/absolute-urls.css', 'a{background:url(https://cdn.example.com/a.png)} b{background:url(//cdn.example.com/b.png)} i{background:url(data:image/png;base64,AAAA)} u{background:url(/root/u.png)} s{background:url(#frag)}');
        file_put_contents($this->assets . '/invoiceplane/css/breakout.css', 'p{color:red} </style><script>alert(1)</script><style>');
        file_put_contents($this->assets . '/invoiceplane/css/with-imports.css', '@import "base.css"; @import \'sub/more.css\'; @import url(plain.css); @import "../img/up.css"; @import "https://cdn.example.com/font.css"; @import "//cdn.example.com/font2.css"; @import "/root/abs.css"; body { margin: 0 }');
        mkdir($this->assets . '/a=b/css', 0o775, true);
        file_put_contents($this->assets . '/a=b/css/templates.css', 'body { font-family: freemono; }');
        mkdir($this->assets . '/a&b/css', 0o775, true);
        file_put_contents($this->assets . '/a&b/css/templates.css', 'body { font-family: freemono; }');
        file_put_contents($this->assets . '/invoiceplane/css/readme.txt', 'not a stylesheet');
        file_put_contents($this->root . '/secret.css', 'SECRET-OUTSIDE-ASSETS');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pathsLeavingTheAssetsFolder(): array
    {
        return [
            'dot dot segment'       => ['assets/../secret.css'],
            'dot dot below a theme' => ['assets/invoiceplane/../../secret.css'],
            'encoded dot dot'       => ['assets/%2e%2e/secret.css'],
            'encoded slash'         => ['assets/invoiceplane/css/..%2f..%2f..%2fsecret.css'],
            'backslash'             => ['assets/invoiceplane\\..\\..\\secret.css'],
            'null byte'             => ['assets/invoiceplane/css/templates.css%00.css'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function linkSpellings(): array
    {
        $href = self::BASE . 'assets/invoiceplane/css/templates.css?v=1';

        return [
            'rel before href'        => ['<link rel="stylesheet" href="' . $href . '">'],
            'href before rel'        => ['<link href="' . $href . '" rel="stylesheet">'],
            'single quotes'          => ["<link rel='stylesheet' href='" . $href . "'>"],
            'self closing'           => ['<link rel="stylesheet" href="' . $href . '" />'],
            'type attribute'         => ['<link rel="stylesheet" type="text/css" href="' . $href . '">'],
            'upper case'             => ['<LINK REL="StyleSheet" HREF="' . $href . '">'],
            'spread over lines'      => ["<link\n    rel=\"stylesheet\"\n    href=\"" . $href . "\"\n>"],
            'html encoded ampersand' => ['<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/templates.css?v=1&amp;x=2">'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function alternateStylesheets(): array
    {
        $href = self::BASE . 'assets/invoiceplane/css/templates.css';

        return [
            'alternate stylesheet' => ['<link rel="alternate stylesheet" href="' . $href . '" title="Dark">'],
            'stylesheet alternate' => ['<link rel="stylesheet alternate" href="' . $href . '" title="Dark">'],
            'upper case'           => ['<link rel="ALTERNATE StyleSheet" href="' . $href . '">'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unquotedLinks(): array
    {
        $href = self::BASE . 'assets/invoiceplane/css/templates.css?v=1';

        return [
            'unquoted rel and href'        => ['<link rel=stylesheet href=' . $href . '>'],
            'unquoted, href first'         => ['<link href=' . $href . ' rel=stylesheet>'],
            'unquoted with a type'         => ['<link rel=stylesheet type=text/css href=' . $href . '>'],
            'unquoted rel, quoted href'    => ['<link rel=stylesheet href="' . $href . '">'],
            'quoted rel, unquoted href'    => ['<link rel="stylesheet" href=' . $href . '>'],
            'spaces around the equal sign' => ['<link rel = stylesheet href = ' . $href . '>'],
        ];
    }

    #[Test]
    public function it_replaces_a_link_to_a_local_stylesheet_with_its_content(): void
    {
        /* Arrange */
        $html = '<html><head><link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/templates.css" type="text/css"></head><body>x</body></html>';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringNotContainsString('<link', $result, 'No request to the site itself may remain.');
        self::assertStringContainsString('<style type="text/css">body { font-family: freemono; }' . "\n" . 'table { width: 100%; }</style>', $result);
        self::assertStringContainsString('<body>x</body>', $result, 'The rest of the document is untouched.');
    }

    #[Test]
    public function it_looks_for_the_file_without_the_version_query(): void
    {
        /* Arrange: _theme_asset() and _core_asset() append ?v=<version> */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/core/css/custom-pdf.css?v=1.7.2" type="text/css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringContainsString('/* custom */ p { color: red; }', $result);
        self::assertStringNotContainsString('<link', $result);
    }

    #[Test]
    public function it_inlines_every_local_stylesheet_in_document_order(): void
    {
        /* Arrange: the shipped templates link the theme stylesheet first and custom-pdf.css second, which overrides it */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/templates.css?v=1"><link rel="stylesheet" href="' . self::BASE . 'assets/core/css/custom-pdf.css?v=1">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertLessThan(strpos($result, 'color: red'), strpos($result, 'font-family: freemono'), 'custom-pdf.css must stay after the theme stylesheet.');
        self::assertSame(2, substr_count($result, '<style'));
    }

    #[Test]
    public function it_leaves_external_stylesheets_alone(): void
    {
        /* Arrange */
        $html = '<link rel="stylesheet" href="https://cdn.example.com/assets/invoiceplane/css/templates.css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertSame($html, $result);
    }

    #[Test]
    public function it_leaves_a_host_that_only_starts_like_this_one_alone(): void
    {
        /* Arrange */
        $html = '<link rel="stylesheet" href="https://invoices.example.com.evil.test/assets/invoiceplane/css/templates.css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertSame($html, $result);
    }

    #[Test]
    public function it_leaves_a_foreign_url_alone_even_when_its_tail_looks_like_a_local_path(): void
    {
        /* Arrange: the part of this URL after the length of our own prefix is "invoiceplane/css/templates.css" */
        $foreign = str_pad('https://cdn.example.com/', strlen(self::BASE . 'assets/'), 'x') . 'invoiceplane/css/templates.css';
        $html    = '<link rel="stylesheet" href="' . $foreign . '">';

        /* Act */
        $result = $this->inline($html);

        /* Assert: the origin is checked, not just a length */
        self::assertSame($html, $result);
    }

    #[Test]
    public function it_leaves_a_link_alone_when_the_file_does_not_exist(): void
    {
        /* Arrange */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/missing.css?v=1">';

        /* Act */
        $result = $this->inline($html);

        /* Assert: mPDF may still try to fetch it, exactly as before */
        self::assertSame($html, $result);
    }

    #[Test]
    #[DataProvider('pathsLeavingTheAssetsFolder')]
    public function it_never_reads_a_file_outside_the_assets_folder(string $path): void
    {
        /* Arrange: a stylesheet that exists, but outside assets/ */
        $html = '<link rel="stylesheet" href="' . self::BASE . $path . '">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringNotContainsString('SECRET-OUTSIDE-ASSETS', $result);
        self::assertSame($html, $result, 'A link that cannot be resolved inside assets/ stays as it was.');
    }

    #[Test]
    public function it_leaves_a_folder_that_is_named_like_a_stylesheet_alone(): void
    {
        /* Arrange */
        mkdir($this->assets . '/invoiceplane/css/folder.css', 0o775);
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/folder.css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert: a folder is not a stylesheet, and no empty style element replaces the link */
        self::assertSame($html, $result);
    }

    #[Test]
    public function it_only_inlines_css_files(): void
    {
        /* Arrange */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/readme.txt">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertSame($html, $result);
    }

    #[Test]
    public function it_ignores_links_that_are_not_stylesheets(): void
    {
        /* Arrange */
        $html = '<link rel="icon" href="' . self::BASE . 'assets/invoiceplane/css/templates.css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertSame($html, $result);
    }

    #[Test]
    #[DataProvider('linkSpellings')]
    public function it_recognises_the_usual_spellings_of_a_stylesheet_link(string $link): void
    {
        /* Arrange: see the data provider */

        /* Act */
        $result = $this->inline('<head>' . $link . '</head>');

        /* Assert */
        self::assertStringNotContainsString('<link', strtolower($result));
        self::assertStringContainsString('font-family: freemono', $result);
    }

    #[Test]
    public function it_resolves_quoted_imports_against_the_original_stylesheet_url(): void
    {
        /* Arrange: an @import inside inlined CSS would otherwise resolve against the document, not the stylesheet */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/with-imports.css?v=1">';
        $css  = self::BASE . 'assets/invoiceplane/';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringContainsString('@import "' . $css . 'css/base.css"', $result);
        self::assertStringContainsString("@import '" . $css . "css/sub/more.css'", $result);
        self::assertStringContainsString('@import url(' . $css . 'css/plain.css)', $result, 'The unquoted url() form was already resolved.');
        self::assertStringContainsString('@import "' . $css . 'img/up.css"', $result, '../ is resolved.');
    }

    #[Test]
    public function it_leaves_absolute_imports_alone(): void
    {
        /* Arrange */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/with-imports.css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        foreach (['@import "https://cdn.example.com/font.css"', '@import "//cdn.example.com/font2.css"', '@import "/root/abs.css"'] as $import) {
            self::assertStringContainsString($import, $result);
        }
    }

    #[Test]
    public function it_decodes_html_entities_in_the_href_before_looking_for_the_file(): void
    {
        /* Arrange: _theme_asset() writes the theme name through htmlsc(), so a folder called a&b is linked as a&amp;b */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/a&amp;b/css/templates.css?v=1">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringNotContainsString('<link', $result);
        self::assertStringContainsString('font-family: freemono', $result);
    }

    #[Test]
    public function it_matches_the_whole_link_when_an_attribute_contains_a_greater_than_sign(): void
    {
        /* Arrange: a ">" inside a quoted attribute value does not end the tag */
        $html = '<head><link rel="stylesheet" title="a>b" href="' . self::BASE . 'assets/invoiceplane/css/templates.css"><link title=\'x>y\' rel="stylesheet" href="' . self::BASE . 'assets/core/css/custom-pdf.css"></head>';

        /* Act */
        $result = $this->inline($html);

        /* Assert: no remnant of either tag is left behind as text */
        self::assertStringNotContainsString('<link', $result);
        self::assertStringNotContainsString('href=', $result);
        self::assertStringNotContainsString('b"', $result);
        self::assertStringContainsString('font-family: freemono', $result);
        self::assertStringContainsString('/* custom */', $result);
        self::assertStringEndsWith('</style></head>', $result);
    }

    #[Test]
    #[DataProvider('alternateStylesheets')]
    public function it_leaves_an_alternate_stylesheet_alone(string $link): void
    {
        /* Arrange: an alternate stylesheet is not applied by default, inlining it would switch it on */

        /* Act */
        $result = $this->inline($link);

        /* Assert */
        self::assertSame($link, $result);
    }

    #[Test]
    #[DataProvider('unquotedLinks')]
    public function it_reads_unquoted_attribute_values(string $link): void
    {
        /* Arrange: see the data provider */

        /* Act */
        $result = $this->inline('<head>' . $link . '</head>');

        /* Assert */
        self::assertStringNotContainsString('<link', $result);
        self::assertStringContainsString('font-family: freemono', $result);
    }

    #[Test]
    public function it_accepts_an_equal_sign_inside_an_unquoted_value(): void
    {
        /* Arrange: browsers keep reading an unquoted value up to the next space or ">", = included */
        $html = '<link rel=stylesheet href=' . self::BASE . 'assets/a=b/css/templates.css>';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringNotContainsString('<link', $result);
        self::assertStringContainsString('font-family: freemono', $result);
    }

    #[Test]
    public function it_keeps_an_unquoted_media_attribute(): void
    {
        /* Arrange */
        $html = '<link rel=stylesheet media=print href=' . self::BASE . 'assets/core/css/custom-pdf.css>';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringContainsString('<style type="text/css" media="print">', $result);
    }

    #[Test]
    public function it_does_not_mistake_data_attributes_for_the_real_ones(): void
    {
        /* Arrange: data-href and data-rel carry other values than the real attributes */
        $html = '<link data-href="https://cdn.example.com/x.css" rel="stylesheet" data-rel="icon" href="' . self::BASE . 'assets/invoiceplane/css/templates.css">';
        $only = '<link data-href="' . self::BASE . 'assets/invoiceplane/css/templates.css" rel="stylesheet">';

        /* Act */
        $result     = $this->inline($html);
        $resultOnly = $this->inline($only);

        /* Assert */
        self::assertStringNotContainsString('<link', $result, 'The real href is the local one.');
        self::assertStringContainsString('font-family: freemono', $result);
        self::assertSame($only, $resultOnly, 'A link with only a data-href has no href.');
    }

    #[Test]
    public function it_uses_the_first_of_two_identical_attributes_like_a_browser(): void
    {
        /* Arrange: a foreign href first, a local one second, and the other way round */
        $foreignFirst = '<link rel="stylesheet" href="https://cdn.example.com/x.css" href="' . self::BASE . 'assets/invoiceplane/css/templates.css">';
        $localFirst   = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/templates.css" href="https://cdn.example.com/x.css">';

        /* Act */
        $first  = $this->inline($foreignFirst);
        $second = $this->inline($localFirst);

        /* Assert */
        self::assertSame($foreignFirst, $first, 'The browser would fetch the foreign stylesheet, so nothing is inlined.');
        self::assertStringContainsString('font-family: freemono', $second);
        self::assertStringNotContainsString('<link', $second);
    }

    #[Test]
    public function it_keeps_the_media_attribute(): void
    {
        /* Arrange */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/core/css/custom-pdf.css" media="print">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringContainsString('<style type="text/css" media="print">', $result);
    }

    #[Test]
    public function it_cannot_be_broken_out_of_by_the_content_of_a_stylesheet(): void
    {
        /* Arrange: a stylesheet that contains a closing style tag */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/breakout.css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert: the only closing tag is the one the function writes itself, at the very end, so the content stays inside the element */
        self::assertSame(1, substr_count(strtolower($result), '</style'));
        self::assertStringEndsWith('</style>', $result);
        self::assertStringContainsString('<\\/style', $result, 'The closing tag in the stylesheet is neutralised.');
    }

    #[Test]
    public function it_resolves_relative_urls_in_the_css_against_the_original_stylesheet_url(): void
    {
        /* Arrange: a stylesheet that was linked resolved url() against its own address; inlined it must keep doing so */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/with-urls.css?v=1">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertStringContainsString('url(' . self::BASE . 'assets/invoiceplane/css/pixel.png)', $result);
        self::assertStringContainsString('url("' . self::BASE . 'assets/invoiceplane/img/b.png")', $result, '../ is resolved and the quotes stay.');
        self::assertStringContainsString("url('" . self::BASE . "assets/invoiceplane/css/img/i.png')", $result);
        self::assertStringContainsString('url(' . self::BASE . 'assets/invoiceplane/css/spaced.png)', $result);
    }

    #[Test]
    public function it_leaves_absolute_urls_in_the_css_alone(): void
    {
        /* Arrange */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/absolute-urls.css">';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        foreach (['url(https://cdn.example.com/a.png)', 'url(//cdn.example.com/b.png)', 'url(data:image/png;base64,AAAA)', 'url(/root/u.png)', 'url(#frag)'] as $url) {
            self::assertStringContainsString($url, $result);
        }
    }

    #[Test]
    public function it_works_below_a_sub_directory(): void
    {
        /* Arrange: IIS virtual directory or sub folder install */
        $html = '<link rel="stylesheet" href="https://example.com/billing/assets/invoiceplane/css/templates.css?v=1">';

        /* Act */
        $result = pdf_inline_local_stylesheets($html, 'https://example.com/billing/', $this->assets);

        /* Assert */
        self::assertStringContainsString('font-family: freemono', $result);
        self::assertStringNotContainsString('<link', $result);
    }

    #[Test]
    public function it_returns_a_document_without_stylesheet_links_unchanged(): void
    {
        /* Arrange */
        $html = '<html><head><style>p{margin:0}</style></head><body><p>No links here</p></body></html>';

        /* Act */
        $result = $this->inline($html);

        /* Assert */
        self::assertSame($html, $result);
    }

    #[Test]
    public function it_leaves_the_document_alone_when_the_assets_folder_does_not_exist(): void
    {
        /* Arrange: an installation whose assets are served from somewhere else */
        $html = '<link rel="stylesheet" href="' . self::BASE . 'assets/invoiceplane/css/templates.css?v=1">';

        /* Act */
        $result = pdf_inline_local_stylesheets($html, self::BASE, $this->root . '/no-such-assets-folder');

        /* Assert: mPDF is left to fetch it, as before */
        self::assertSame($html, $result);
    }

    #[Test]
    public function it_returns_an_empty_document_unchanged(): void
    {
        /* Arrange + Act */
        $result = $this->inline('');

        /* Assert */
        self::assertSame('', $result);
    }

    private function inline(string $html): string
    {
        return pdf_inline_local_stylesheets($html, self::BASE, $this->assets);
    }
}
