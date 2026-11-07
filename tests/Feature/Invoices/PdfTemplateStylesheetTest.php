<?php

namespace Tests\Feature\Invoices;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * After upgrading to 1.7.2 (mPDF 8.3.1) generated PDFs lost every template style whenever PHP could not fetch the
 * site's own stylesheet URL: mPDF fetches the absolute base_url() link remotely, and its fallback to the local file
 * is inverted. In this suite http://localhost/ is not served, which is the same situation as a Windows PHP without a
 * CA bundle, a self-signed certificate or a container that cannot reach its public address.
 *
 * The PDF is checked for the font the stylesheet sets (FreeMono ships with mPDF and is not a default), because mPDF
 * swallows stylesheet errors and the content of the PDF looks right either way.
 */
#[Group('pdf')]
final class PdfTemplateStylesheetTest extends AbstractTestCase
{
    private const THEME = 'zz_pdf_css_theme';

    private string $themeDir;

    /** @var list<string> */
    private array $archived = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->themeDir = ROOT_PATH . '/public/assets/' . self::THEME;
        $this->setSetting('pdf_invoice_template', 'InvoicePlane');
    }

    protected function tearDown(): void
    {
        foreach ($this->archived as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $css = $this->themeDir . '/css/templates.css';
        if (is_file($css)) {
            unlink($css);
        }
        foreach ([$this->themeDir . '/css', $this->themeDir] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function it_applies_the_template_stylesheet_although_the_site_url_cannot_be_fetched(): void
    {
        /* Arrange: the theme's stylesheet exists on disk and sets a font that is not mPDF's default */
        mkdir($this->themeDir . '/css', 0o775, true);
        file_put_contents($this->themeDir . '/css/templates.css', 'body, div, p, td, th, span { font-family: freemono; }');
        $this->setSetting('system_theme', self::THEME);
        $invoice = $this->seedInvoice($this->seedClient(), ['invoice_number' => 'ZZCSS1001', 'invoice_status_id' => 2], ['invoice_total' => '100.00', 'invoice_balance' => '100.00']);

        /* Act */
        $pdf = $this->generatePdf($invoice);

        /* Assert */
        self::assertSame('%PDF', substr($pdf, 0, 4));
        self::assertTrue(str_contains($pdf, 'FreeMono'), 'The stylesheet of the theme must reach the PDF without any request to the site itself.');
    }

    #[Test]
    public function it_renders_without_the_font_when_the_theme_has_no_stylesheet(): void
    {
        /* Arrange: control, the same invoice with a theme whose stylesheet does not exist, so the font can only come from the CSS */
        $this->setSetting('system_theme', self::THEME);
        $invoice = $this->seedInvoice($this->seedClient(), ['invoice_number' => 'ZZCSS1002', 'invoice_status_id' => 2], ['invoice_total' => '100.00', 'invoice_balance' => '100.00']);

        /* Act */
        $pdf = $this->generatePdf($invoice);

        /* Assert */
        self::assertSame('%PDF', substr($pdf, 0, 4));
        self::assertFalse(str_contains($pdf, 'FreeMono'), 'Without a stylesheet the font must not appear, otherwise the other test proves nothing.');
    }

    private function generatePdf(int $invoiceId): string
    {
        $before = $this->archiveFiles();
        $this->assertResponseStatusCode($this->get('/invoices/generate_pdf/' . $invoiceId), 200);
        $new            = array_values(array_diff($this->archiveFiles(), $before));
        $this->archived = array_map(static fn (string $f): string => ROOT_PATH . '/uploads/archive/' . $f, $new);

        self::assertCount(1, $new, 'The generated PDF is archived, and that copy is what is inspected.');

        return (string) file_get_contents($this->archived[0]);
    }

    /**
     * @return list<string>
     */
    private function archiveFiles(): array
    {
        return array_values(array_diff(scandir(ROOT_PATH . '/uploads/archive') ?: [], ['.', '..', 'index.html', '.htaccess']));
    }

    private function setSetting(string $key, string $value): void
    {
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => $key, 'setting_value' => $value]);
        $this->databaseUpdate('ip_settings', ['setting_value' => $value], ['setting_key' => $key]);
    }
}
