<?php

namespace Tests\Feature\Invoices;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;
use Tests\Integration\Support\HttpResponse;

/**
 * Upstream #944 (issue #925): with the setting 'Show Options menu-item [Preview PDF]' switched on, the
 * invoice and quote lists and detail pages offer "Preview PDF", which loads a modal that embeds the
 * document's PDF, instead of the "Download PDF" entry.
 *
 * The click and the browser's PDF viewer cannot run here; what is pinned is what the server controls:
 * the modal markup the click loads (the PDF URL it embeds and the document it names), the menu entry
 * that is rendered for each value of the setting, and that the script on the list page targets the
 * element that is rendered.
 */
#[Group('feature')]
final class PreviewPdfModalTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    #[Test]
    public function it_embeds_the_pdf_of_the_invoice_in_the_invoice_modal(): void
    {
        /* Arrange */
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_number' => 'PRV-INV-0042']);

        /* Act */
        $response = $this->postModal('/invoices/ajax/modal_preview_pdf', ['invoice_id' => (string) $invoiceId]);

        /* Assert */
        self::assertSame(200, $response->statusCode(), $response->body());
        $this->assertResponseBodyContains($response, 'id="modal-preview-pdf"');
        $this->assertResponseBodyContains($response, 'type="application/pdf"');
        $this->assertResponseBodyContains($response, 'invoices/generate_pdf/' . $invoiceId . '#view=FitH');
        $this->assertResponseBodyContains($response, 'PRV-INV-0042');
    }

    #[Test]
    public function it_embeds_the_pdf_of_the_quote_in_the_quote_modal(): void
    {
        /* Arrange */
        $quoteId = $this->seedQuoteWithAmounts('PRV-QUO-0007');

        /* Act */
        $response = $this->postModal('/quotes/ajax/modal_preview_pdf', ['quote_id' => (string) $quoteId]);

        /* Assert */
        self::assertSame(200, $response->statusCode(), $response->body());
        $this->assertResponseBodyContains($response, 'id="modal-preview-pdf"');
        $this->assertResponseBodyContains($response, 'quotes/generate_pdf/' . $quoteId . '#view=FitH');
        $this->assertResponseBodyContains($response, 'PRV-QUO-0007');
    }

    #[Test]
    public function it_offers_download_pdf_and_no_preview_entry_while_the_setting_is_off(): void
    {
        /* Arrange */
        $this->setSetting('show_menu_item_preview_pdf', '0');
        $invoiceId = $this->seedInvoice($this->seedClient());

        /* Act */
        $list = $this->get('/invoices/status/all');
        $view = $this->get('/invoices/view/' . $invoiceId);

        /* Assert */
        $this->assertResponseBodyContains($list, 'invoices/generate_pdf/' . $invoiceId);
        $this->assertResponseBodyNotContains($list, 'class="invoice_preview_pdf"');
        $this->assertResponseBodyContains($view, 'id="btn_generate_pdf"');
        $this->assertResponseBodyNotContains($view, 'id="btn_preview_pdf"');
    }

    #[Test]
    public function it_swaps_download_pdf_for_a_preview_entry_while_the_setting_is_on(): void
    {
        /* Arrange */
        $this->setSetting('show_menu_item_preview_pdf', '1');
        $invoiceId = $this->seedInvoice($this->seedClient());

        /* Act */
        $list = $this->get('/invoices/status/all');
        $view = $this->get('/invoices/view/' . $invoiceId);

        /* Assert */
        $this->assertResponseBodyContains($list, 'class="invoice_preview_pdf"');
        $this->assertResponseBodyContains($list, 'data-invoice-id="' . $invoiceId . '"');
        $this->assertResponseBodyNotContains($list, 'invoices/generate_pdf/' . $invoiceId);
        $this->assertResponseBodyContains($view, 'id="btn_preview_pdf"');
        $this->assertResponseBodyNotContains($view, 'id="btn_generate_pdf"');
    }

    #[Test]
    public function it_binds_the_list_script_to_the_class_the_preview_entry_carries(): void
    {
        /* Arrange: the script delegates the click to a selector; the entry has to carry that class */
        $this->setSetting('show_menu_item_preview_pdf', '1');
        $this->seedInvoice($this->seedClient());

        /* Act */
        $list = $this->get('/invoices/status/all');

        /* Assert */
        self::assertSame(1, preg_match("/\\\$\(document\)\.on\('click', '\.([a-z_]+)'/", $list->body(), $selector), 'The list page has no delegated click handler.');
        $this->assertResponseBodyContains($list, 'class="' . $selector[1] . '"');
    }

    #[Test]
    public function it_offers_the_same_entries_for_quotes_on_the_quote_list_and_detail_page(): void
    {
        /* Arrange */
        $quoteId = $this->seedQuoteWithAmounts('PRV-QUO-0009');

        /* Act */
        $this->setSetting('show_menu_item_preview_pdf', '1');
        $listOn = $this->get('/quotes/status/all');
        $viewOn = $this->get('/quotes/view/' . $quoteId);
        $this->setSetting('show_menu_item_preview_pdf', '0');
        $listOff = $this->get('/quotes/status/all');
        $viewOff = $this->get('/quotes/view/' . $quoteId);

        /* Assert */
        $this->assertResponseBodyContains($listOn, 'class="quote_preview_pdf"');
        self::assertSame(1, preg_match("/\\\$\(document\)\.on\('click', '\.([a-z_]+)'/", $listOn->body(), $selector));
        self::assertSame('quote_preview_pdf', $selector[1]);
        $this->assertResponseBodyContains($viewOn, 'id="btn_preview_pdf"');
        $this->assertResponseBodyNotContains($listOff, 'class="quote_preview_pdf"');
        $this->assertResponseBodyNotContains($viewOff, 'id="btn_preview_pdf"');
    }

    #[Test]
    public function it_renders_the_setting_in_the_general_settings_form(): void
    {
        /* Arrange */
        $this->setSetting('show_menu_item_preview_pdf', '0');

        /* Act */
        $form = $this->get('/settings');

        /* Assert: the select is rendered so the setting can be switched on */
        $this->assertResponseBodyContains($form, 'name="settings[show_menu_item_preview_pdf]"');
    }

    #[Test]
    public function it_escapes_the_document_number_it_prints_into_the_modal_header(): void
    {
        /* Arrange: numbers are free text (the identifier format of an invoice group) */
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_number' => '<img src=x onerror=alert(1)>']);
        $quoteId   = $this->seedQuoteWithAmounts('<script>alert(2)</script>');

        /* Act */
        $invoiceModal = $this->postModal('/invoices/ajax/modal_preview_pdf', ['invoice_id' => (string) $invoiceId]);
        $quoteModal   = $this->postModal('/quotes/ajax/modal_preview_pdf', ['quote_id' => (string) $quoteId]);

        /* Assert */
        $this->assertResponseBodyNotContains($invoiceModal, '<img src=x');
        $this->assertResponseBodyContains($invoiceModal, '&lt;img src=x onerror=alert(1)&gt;');
        $this->assertResponseBodyNotContains($quoteModal, '<script>alert(2)');
        $this->assertResponseBodyContains($quoteModal, '&lt;script&gt;alert(2)&lt;/script&gt;');
    }

    #[Test]
    public function it_answers_404_instead_of_a_php_error_for_a_document_that_does_not_exist(): void
    {
        /* Arrange / Act */
        $invoiceModal = $this->postModal('/invoices/ajax/modal_preview_pdf', ['invoice_id' => '987654']);
        $quoteModal   = $this->postModal('/quotes/ajax/modal_preview_pdf', ['quote_id' => '987654']);

        /* Assert */
        self::assertSame(404, $invoiceModal->statusCode(), $invoiceModal->body());
        self::assertSame(404, $quoteModal->statusCode(), $quoteModal->body());
    }

    #[Test]
    public function it_only_ever_puts_a_whole_number_into_the_pdf_url(): void
    {
        /* Arrange */
        $invoiceId = $this->seedInvoice($this->seedClient());

        /* Act: the id is posted data and ends up in an attribute */
        $response = $this->postModal('/invoices/ajax/modal_preview_pdf', ['invoice_id' => $invoiceId . '"><b>']);

        /* Assert */
        self::assertSame(200, $response->statusCode(), $response->body());
        $this->assertResponseBodyContains($response, 'invoices/generate_pdf/' . $invoiceId . '#view=FitH');
        $this->assertResponseBodyNotContains($response, '"><b>');
    }

    /**
     * @param array<string, string> $data
     */
    private function postModal(string $uri, array $data): HttpResponse
    {
        return $this->request('POST', $uri, [], $data + ['_ip_csrf' => $this->csrfToken], true, ['ip_csrf_cookie' => $this->csrfToken]);
    }

    private function setSetting(string $key, string $value): void
    {
        if ($this->databaseFetchOne('ip_settings', ['setting_key' => $key]) === null) {
            $this->databaseInsert('ip_settings', ['setting_key' => $key, 'setting_value' => $value]);

            return;
        }

        $this->databaseUpdate('ip_settings', ['setting_value' => $value], ['setting_key' => $key]);
    }

    private function seedQuoteWithAmounts(string $number): int
    {
        $quoteId = $this->databaseInsert('ip_quotes', [
            'user_id'            => 1, 'client_id' => $this->seedClient(), 'invoice_group_id' => 1, 'quote_status_id' => 1,
            'quote_number'       => $number, 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ]);
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id' => $quoteId, 'quote_item_subtotal' => '0.00', 'quote_item_tax_total' => '0.00', 'quote_tax_total' => '0.00', 'quote_total' => '0.00',
        ]);

        return $quoteId;
    }
}
