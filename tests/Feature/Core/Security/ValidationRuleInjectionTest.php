<?php

namespace Tests\Feature\Core\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * `invoices/ajax/save` and `quotes/ajax/save` passed the posted invoice_id / quote_id through
 * xss_clean() and set_id() unchanged, and Mdl_Invoices::validation_rules_save_invoice() /
 * Mdl_Quotes::validation_rules_save_quote() concatenated it straight into a CodeIgniter
 * validation-rule string: `is_unique[ip_invoices.invoice_number.invoice_id.<id>]`. CodeIgniter's
 * Form_validation splits that string on `|` outside brackets and, for a token that is not a
 * known validation method, calls it as a native PHP function with the field's own POST value as
 * the argument (CWE-94). Posting `invoice_id=1]|strtoupper|trim[` makes the framework call
 * strtoupper() on the submitted invoice_number and store the upper-cased result — proof that an
 * attacker-chosen one-argument function runs, without actually invoking anything destructive
 * (no system()/exec() call is made from this test).
 *
 * Reported by Rick Weng (@rickwengdev).
 */
#[Group('security')]
final class ValidationRuleInjectionTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    #[Test]
    public function it_does_not_let_the_posted_invoice_id_inject_an_extra_validation_rule(): void
    {
        /* Arrange: a real invoice so is_unique's companion id lookup has a row to compare against */
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_number' => 'baseline']);

        /* Act: the id breaks out of the is_unique[...] rule and appends a harmless function call */
        $response = $this->ajax('POST', '/invoices/ajax/save', [
            'invoice_id'           => $invoiceId . ']|strtoupper|trim[',
            'invoice_number'       => 'injected-marker',
            'invoice_date_created' => date('Y-m-d'),
            'invoice_date_due'     => date('Y-m-d', strtotime('+30 days')),
            'invoice_time_created' => date('H:i:s'),
            'items'                => '[]',
        ]);

        /* Assert: the (int) cast reads this as a plain update of $invoiceId, so it succeeds — but with the
         * submitted value stored exactly as submitted; it is lower case, strtoupper's output is not. */
        $json   = json_decode($response->body(), true);
        $stored = $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId])['invoice_number'];
        self::assertSame(1, $json['success'] ?? null, 'Body: ' . $response->body());
        self::assertNotSame('INJECTED-MARKER', $stored, 'A function named by the attacker must not run on the posted field value.');
        self::assertSame('injected-marker', $stored);
    }

    #[Test]
    public function it_does_not_let_the_posted_quote_id_inject_an_extra_validation_rule(): void
    {
        /* Arrange */
        $quoteId = $this->seedQuote($this->seedClient(), ['quote_number' => 'qbaseline']);

        /* Act */
        $response = $this->ajax('POST', '/quotes/ajax/save', [
            'quote_id'           => $quoteId . ']|strtoupper|trim[',
            'quote_number'       => 'qinjected-marker',
            'quote_date_created' => date('Y-m-d'),
            'quote_date_expires' => date('Y-m-d', strtotime('+15 days')),
            'items'              => '[]',
        ]);

        /* Assert: same reasoning as the invoice test above */
        $json   = json_decode($response->body(), true);
        $stored = $this->databaseFetchOne('ip_quotes', ['quote_id' => $quoteId])['quote_number'];
        self::assertSame(1, $json['success'] ?? null, 'Body: ' . $response->body());
        self::assertNotSame('QINJECTED-MARKER', $stored, 'A function named by the attacker must not run on the posted field value.');
        self::assertSame('qinjected-marker', $stored);
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
