<?php

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-ph5g-ffvw-r5vq: the document filename (derived from the free-text invoice/quote number) was
 * concatenated raw into the mPDF footer HTML. mPDF fetches remote resources referenced in that HTML,
 * so an <img src="http://internal/..."> in the number became a server-side request.
 */
class PdfSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT_PATH . '/application/helpers/mpdf_helper.php';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileFilenames(): array
    {
        return [
            'imds img'          => ['<img src="http://169.254.169.254/latest/meta-data/">'],
            'img no quotes'     => ['<img src=http://internal.example/x>'],
            'script'            => ['<script>alert(1)</script>'],
            'attribute breakout' => ['x" onerror="alert(1)'],
            'single quotes'     => ["x' onerror='alert(1)"],
            'link stylesheet'   => ['<link rel=stylesheet href=http://attacker.example/x.css>'],
            'entity smuggling'  => ['&lt;img src=http://internal.example/x&gt;'],
            'underscore trick'  => ['<img_src="http://internal.example/x">'],
        ];
    }

    #[Test]
    #[DataProvider('hostileFilenames')]
    public function it_escapes_the_filename_shown_in_the_pdf_footer(string $filename): void
    {
        $footer = pdf_footer_filename($filename);

        self::assertDoesNotMatchRegularExpression('/[<>"\']/', $footer, 'raw markup characters must not survive: ' . $footer);
        self::assertStringNotContainsString('<img', $footer);
        self::assertSame($footer, htmlspecialchars(html_entity_decode($footer, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'), 'the output must be a stable, fully escaped string');
    }

    #[Test]
    public function it_turns_underscores_into_spaces_before_escaping(): void
    {
        self::assertSame('Invoice 2026 0001', pdf_footer_filename('Invoice_2026_0001'));
        self::assertSame('a &amp; b', pdf_footer_filename('a_&_b'));
    }

    #[Test]
    public function it_leaves_a_legitimate_filename_readable(): void
    {
        self::assertSame('Rechnung Müller 日本語 - 42', pdf_footer_filename('Rechnung_Müller_日本語_-_42'));
        self::assertSame('', pdf_footer_filename(''));
    }

    #[Test]
    public function pdf_create_never_concatenates_the_raw_filename_into_footer_html(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/application/helpers/mpdf_helper.php');

        preg_match_all('/DefHTMLFooterByName\([^;]*\$filename[^;]*;/', $source, $rawUses);
        self::assertSame([], $rawUses[0], 'every footer that shows the filename must use pdf_footer_filename()');

        self::assertSame(2, substr_count($source, 'pdf_footer_filename($filename)'), 'both the invoice and the quote footer must escape the filename');
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function footerPayloads(): array
    {
        return [
            'img'                 => ['<img src="http://169.254.169.254/">', ['<img']],
            'img uppercase'       => ['<IMG SRC="http://169.254.169.254/">', ['<img', '<IMG']],
            'img inside allowed tag' => ['<p><img src="http://internal.example/x"></p>', ['<img']],
            'svg'                 => ['<svg><image href="http://internal.example/x"/></svg>', ['<svg', '<image']],
            'iframe'              => ['<iframe src="http://internal.example/x"></iframe>', ['<iframe']],
            'object'              => ['<object data="http://internal.example/x"></object>', ['<object']],
            'link'                => ['<link rel="stylesheet" href="http://internal.example/x.css">', ['<link']],
            'style block'         => ['<style>@import url(http://internal.example/x.css);</style>', ['<style', '@import']],
            'css url attribute'   => ['<span style="background:url(http://internal.example/x)">t</span>', ['url(', 'style=']],
            'event handler'       => ['<b onmouseover="alert(1)">t</b>', ['onmouseover']],
            'nul bytes in tag'    => ["<im\x00g src=http://internal.example/x>", ['http://internal.example']],
        ];
    }

    /**
     * @param list<string> $forbidden
     */
    #[Test]
    #[DataProvider('footerPayloads')]
    public function it_strips_everything_that_could_make_mpdf_fetch_a_remote_resource(string $payload, array $forbidden): void
    {
        $result = sanitize_pdf_footer_content($payload);

        foreach ($forbidden as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $result, 'sanitised footer still contains ' . $needle . ': ' . $result);
        }
    }

    #[Test]
    public function it_keeps_the_formatting_tags_an_operator_legitimately_uses_in_a_footer(): void
    {
        $result = sanitize_pdf_footer_content('<b>Important</b> <i>Notice</i><br>IBAN <span>NL00 TEST</span>');

        self::assertStringContainsString('<b>Important</b>', $result);
        self::assertStringContainsString('<i>Notice</i>', $result);
        self::assertStringContainsString('NL00 TEST', $result);
    }

    #[Test]
    public function it_returns_an_empty_string_for_a_missing_footer(): void
    {
        self::assertSame('', sanitize_pdf_footer_content(null));
        self::assertSame('', sanitize_pdf_footer_content(''));
    }
}
