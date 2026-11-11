<?php

namespace Tests\Feature\Core\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * `invoices/view/<id>` and `quotes/view/<id>` print the raw URL segment, unquoted, into inline JavaScript
 * (`invoice_id: <?php echo $invoice_id; ?>`). `permitted_uri_chars` allows parentheses, `-`, `.` and `:`, and MySQL
 * compares a string like "1-ipauditmarker(1)" against the integer primary key by taking its leading numeric
 * prefix, so the real invoice/quote still loads and the segment reaches the page as a JavaScript expression —
 * reflected XSS in a logged-in administrator's session (CWE-79).
 *
 * `ipauditmarker` (the report's own marker) is an undefined name; it only proves the segment was evaluated as
 * code, it does nothing else.
 *
 * Reported by Rick Weng (@rickwengdev).
 */
#[Group('security')]
final class UnquotedIdInScriptTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    #[Test]
    public function it_does_not_write_the_raw_invoice_id_segment_into_the_page_script(): void
    {
        /* Arrange */
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_number' => 'script-inv']);

        /* Act */
        $response = $this->get('/invoices/view/' . $invoiceId . '-ipauditmarker(1)');

        /* Assert: the real invoice still renders (MySQL's numeric-prefix comparison still matches it), but
         * the id written into every inline script must be the plain integer, not the attacker's expression */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'script-inv');
        $this->assertResponseBodyNotContains($response, 'ipauditmarker');
        $this->assertResponseBodyContains($response, 'invoice_id: ' . $invoiceId);
    }

    #[Test]
    public function it_does_not_write_the_raw_quote_id_segment_into_the_page_script(): void
    {
        /* Arrange */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'quotes_expire_after', 'setting_value' => '15']);
        $quoteId = $this->seedQuote($this->seedClient(), ['quote_number' => 'script-quo']);

        /* Act */
        $response = $this->get('/quotes/view/' . $quoteId . '-ipauditmarker(1)');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'script-quo');
        $this->assertResponseBodyNotContains($response, 'ipauditmarker');
    }

    /**
     * The modal endpoints take the id from the request body, so a cast in `view()` never reaches them.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function modalsThatPrintAPostedId(): array
    {
        return [
            'copy invoice'          => ['/invoices/ajax/modal_copy_invoice', 'invoice', 'invoice_id: %d,'],
            'create credit'         => ['/invoices/ajax/modal_create_credit', 'invoice', 'invoice_id: %d,'],
            'create recurring'      => ['/invoices/ajax/modal_create_recurring', 'invoice', 'invoice_id: %d,'],
            'change invoice user'   => ['/invoices/ajax/modal_change_user', 'invoice', 'id="invoice_id" value="%d"'],
            'change invoice client' => ['/invoices/ajax/modal_change_client', 'invoice', 'id="invoice_id" value="%d"'],
            'add payment'           => ['/payments/ajax/modal_add_payment', 'invoice', 'id="invoice_id" value="%d"'],
            'copy quote'            => ['/quotes/ajax/modal_copy_quote', 'quote', 'quote_id: %d,'],
            'change quote user'     => ['/quotes/ajax/modal_change_user', 'quote', 'id="quote_id" value="%d"'],
            'change quote client'   => ['/quotes/ajax/modal_change_client', 'quote', 'id="quote_id" value="%d"'],
        ];
    }

    #[Test]
    #[DataProvider('modalsThatPrintAPostedId')]
    public function it_prints_only_the_integer_part_of_a_posted_id_in_a_modal(string $uri, string $type, string $expected): void
    {
        /* Arrange */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'quotes_expire_after', 'setting_value' => '15']);
        $clientId = $this->seedClient();
        $id       = $type === 'invoice' ? $this->seedInvoice($clientId) : $this->seedQuote($clientId);

        /* Act: once breaking out of script, once breaking out of an HTML attribute */
        $script    = $this->ajax('POST', $uri, [$type . '_id' => $id . '-ipauditmarker(1)', 'client_id' => $clientId, 'user_id' => 1]);
        $attribute = $this->ajax('POST', $uri, [$type . '_id' => $id . '" data-ipauditmarker="1', 'client_id' => $clientId, 'user_id' => 1]);

        /* Assert */
        foreach ([$script, $attribute] as $response) {
            $this->assertResponseStatusCode($response, 200);
            $this->assertResponseHasNoPhpErrors($response);
            $this->assertResponseBodyNotContains($response, 'ipauditmarker');
            $this->assertResponseBodyContains($response, sprintf($expected, $id));
        }
    }

    #[Test]
    public function it_prints_only_the_integer_part_of_the_quote_id_segment_in_the_quote_to_invoice_modal(): void
    {
        /* Arrange */
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'quotes_expire_after', 'setting_value' => '15']);
        $quoteId = $this->seedQuote($this->seedClient());

        /* Act */
        $response = $this->ajax('GET', '/quotes/ajax/modal_quote_to_invoice/' . $quoteId . '-ipauditmarker(1)');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyNotContains($response, 'ipauditmarker');
        $this->assertResponseBodyContains($response, 'quote_id: ' . $quoteId . ',');
    }

    #[Test]
    public function it_keeps_a_posted_custom_field_location_out_of_the_form_script(): void
    {
        /* Act: an empty label fails validation, so the form is shown again with the posted values */
        $response = $this->post('/custom_fields/form', [
            'custom_field_table' => 'ip_client_custom', 'custom_field_type' => 'TEXT', 'custom_field_label' => '',
            'custom_field_location' => '0);ipauditmarker(1', 'btn_submit' => '1',
        ]);

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyNotContains($response, 'ipauditmarker');
        $this->assertResponseBodyContains($response, 'updatePositions(optionIndex, 0);');
    }

    #[Test]
    public function it_has_no_view_that_prints_a_record_id_without_an_integer_cast(): void
    {
        /* Arrange: a request cannot reach every view, so the views themselves are read */
        $views = glob(APPPATH . 'modules/*/views/{,*/}*.php', GLOB_BRACE);
        $this->assertGreaterThan(150, count($views));
        $raw = [];

        /* Act */
        foreach ($views as $view) {
            foreach (file($view) as $number => $line) {
                preg_match_all('/<\?(?:php\s+echo|=)(.*?)\?>/', $line, $echoes);
                foreach ($echoes[1] as $echo) {
                    if (preg_match('/(?<!\(int\) )\$(?:invoice_id|quote_id|type_id|custom_field_location)\b/', $echo)
                        || str_contains($echo, 'escape_str(')) {
                        $raw[] = str_replace(APPPATH, '', $view) . ':' . ($number + 1);
                    }
                }
            }
        }

        /* Assert */
        $this->assertSame([], $raw);
    }

    private function seedQuote(int $clientId, array $overrides = []): int
    {
        $quoteId = $this->databaseInsert('ip_quotes', array_merge([
            'user_id'            => 1, 'client_id' => $clientId, 'invoice_group_id' => 1, 'quote_status_id' => 2,
            'quote_number'       => 'QUO-' . bin2hex(random_bytes(3)), 'quote_url_key' => bin2hex(random_bytes(16)),
            'quote_date_created' => date('Y-m-d'), 'quote_date_modified' => date('Y-m-d H:i:s'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
        ], $overrides));
        $this->databaseInsert('ip_quote_amounts', [
            'quote_id'        => $quoteId, 'quote_item_subtotal' => '0.00', 'quote_item_tax_total' => '0.00',
            'quote_tax_total' => '0.00', 'quote_total' => '0.00',
        ]);

        return $quoteId;
    }
}
