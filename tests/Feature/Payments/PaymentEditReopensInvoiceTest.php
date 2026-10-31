<?php

namespace Tests\Feature\Payments;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Integration\Support\HttpResponse;

/**
 * Mdl_payments::save() used to move an invoice forward to Paid but never back: editing a 100.00
 * payment of a 100.00 invoice down to 40.00 left it Paid with a 60.00 balance. delete() already
 * reopened it; saving an edit must agree.
 */
#[Group('payments')]
final class PaymentEditReopensInvoiceTest extends AbstractTestCase
{
    private const STATUS_SENT   = 2;
    private const STATUS_VIEWED = 3;
    private const STATUS_PAID   = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'decimal_point', 'setting_value' => '.']);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'tax_rate_decimal_places', 'setting_value' => '2']);
    }

    #[Test]
    public function it_reopens_a_paid_invoice_when_the_payment_is_edited_to_a_partial_amount(): void
    {
        /* Arrange: a 100.00 invoice, Paid by a single 100.00 payment */
        [$invoiceId, $paymentId] = $this->paidInvoice('100.00');

        /* Act: the bookkeeper corrects the payment to 40.00 */
        $response = $this->editPayment($invoiceId, $paymentId, '40.00');

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A valid edit redirects.');
        $this->assertDatabaseHas('ip_payments', ['payment_id' => $paymentId, 'payment_amount' => '40.00']);
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_paid' => '40.00', 'invoice_balance' => '60.00']);
        $this->assertDatabaseHas('ip_invoices', ['invoice_id' => $invoiceId, 'invoice_status_id' => self::STATUS_SENT]);
    }

    #[Test]
    public function it_offers_the_reopened_invoice_in_the_payment_form_again(): void
    {
        /* Arrange */
        [$invoiceId, $paymentId] = $this->paidInvoice('100.00', 'REOPEN-LIST');
        $this->editPayment($invoiceId, $paymentId, '40.00');

        /* Act: the payment form lists open invoices only */
        $response = $this->get('/payments/form');

        /* Assert */
        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('REOPEN-LIST', $response->body(), 'The invoice with a 60.00 balance must be selectable again.');
    }

    #[Test]
    public function it_keeps_the_invoice_paid_when_the_edited_payment_still_covers_the_total(): void
    {
        /* Arrange: positive control, same request shape but the amount stays 100.00 */
        [$invoiceId, $paymentId] = $this->paidInvoice('100.00');

        /* Act: only the note changes */
        $response = $this->editPayment($invoiceId, $paymentId, '100.00', 'corrected note');

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_payments', ['payment_id' => $paymentId, 'payment_note' => 'corrected note']);
        $this->assertDatabaseHas('ip_invoices', ['invoice_id' => $invoiceId, 'invoice_status_id' => self::STATUS_PAID]);
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_paid' => '100.00', 'invoice_balance' => '0.00']);
    }

    #[Test]
    public function it_does_not_touch_the_status_of_an_invoice_that_was_not_paid(): void
    {
        /* Arrange: a Viewed invoice with a partial payment; Viewed must not be reset to Sent */
        $invoiceId = $this->invoiceWithItem('100.00', self::STATUS_VIEWED);
        $paymentId = $this->seedPayment($invoiceId, ['payment_amount' => '30.00']);

        /* Act */
        $response = $this->editPayment($invoiceId, $paymentId, '20.00');

        /* Assert */
        self::assertTrue($response->isRedirect());
        $this->assertDatabaseHas('ip_invoice_amounts', ['invoice_id' => $invoiceId, 'invoice_paid' => '20.00', 'invoice_balance' => '80.00']);
        $this->assertDatabaseHas('ip_invoices', ['invoice_id' => $invoiceId, 'invoice_status_id' => self::STATUS_VIEWED]);
    }

    #[Test]
    public function it_only_reopens_the_edited_invoice(): void
    {
        /* Arrange: two Paid invoices, only one payment is lowered */
        [$invoiceId, $paymentId] = $this->paidInvoice('100.00');
        [$otherId]               = $this->paidInvoice('100.00');

        /* Act */
        $this->editPayment($invoiceId, $paymentId, '40.00');

        /* Assert */
        $this->assertDatabaseHas('ip_invoices', ['invoice_id' => $invoiceId, 'invoice_status_id' => self::STATUS_SENT]);
        $this->assertDatabaseHas('ip_invoices', ['invoice_id' => $otherId, 'invoice_status_id' => self::STATUS_PAID]);
    }

    private function invoiceWithItem(string $amount, int $status, string $number = ''): int
    {
        $invoiceId = $this->seedInvoice($this->seedClient(), array_filter([
            'invoice_status_id' => $status,
            'invoice_number'    => $number,
        ]), ['invoice_total' => $amount, 'invoice_balance' => $amount]);
        $itemId = $this->databaseInsert('ip_invoice_items', [
            'invoice_id' => $invoiceId, 'item_name' => 'Line', 'item_description' => '', 'item_quantity' => '1', 'item_price' => $amount,
            'item_order' => 1, 'item_date_added' => date('Y-m-d'),
        ]);
        $this->databaseInsert('ip_invoice_item_amounts', [
            'item_id' => $itemId, 'item_subtotal' => $amount, 'item_tax_total' => '0.00', 'item_discount' => '0.00', 'item_total' => $amount,
        ]);

        return $invoiceId;
    }

    /**
     * @return array{0: int, 1: int} [invoiceId, paymentId]
     */
    private function paidInvoice(string $amount, string $number = ''): array
    {
        $invoiceId = $this->invoiceWithItem($amount, self::STATUS_PAID, $number);
        $paymentId = $this->seedPayment($invoiceId, ['payment_amount' => $amount]);
        $this->databaseUpdate('ip_invoice_amounts', ['invoice_paid' => $amount, 'invoice_balance' => '0.00'], ['invoice_id' => $invoiceId]);

        return [$invoiceId, $paymentId];
    }

    private function editPayment(int $invoiceId, int $paymentId, string $amount, string $note = ''): HttpResponse
    {
        return $this->post('/payments/form/' . $paymentId, [
            'payment_id'        => (string) $paymentId,
            'invoice_id'        => (string) $invoiceId,
            'payment_method_id' => '1',
            'payment_amount'    => $amount,
            'payment_date'      => date('Y-m-d'),
            'payment_note'      => $note,
            'btn_submit'        => '1',
        ]);
    }
}
