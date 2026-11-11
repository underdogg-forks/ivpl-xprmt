<?php

namespace Tests\Feature\Core\Security;

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
