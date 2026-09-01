<?php

namespace Tests\Feature\Invoices;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;
use Tests\Concerns\PerformsCsrfProtectedRequests;

/**
 * Recurring controller — application/modules/invoices/controllers/Recurring.php
 * (routes under /invoices/recurring).
 *
 * Recurring schedules are created from an invoice, not through a standalone
 * form, so this controller only lists, stops and deletes them. Absorbs
 * Issue1694RecurringInvoiceDeleteCsrfTest.
 */
#[Group('invoices')]
class RecurringControllerTest extends AbstractTestCase
{
    use PerformsCsrfProtectedRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    #[Test]
    public function it_lists_every_recurring_schedule(): void
    {
        /* Arrange */
        $clientId = $this->seedClient(['client_name' => 'Recurring Client A']);
        $this->seedRecurring($this->seedInvoice($clientId, ['invoice_number' => 'INV-REC-0001']));
        $this->seedRecurring($this->seedInvoice($clientId, ['invoice_number' => 'INV-REC-0002']));

        /* Act */
        $response = $this->get('/invoices/recurring');

        /* Assert */
        $this->assertResponseBodyContains($response, 'INV-REC-0001');
        $this->assertResponseBodyContains($response, 'INV-REC-0002');
    }

    // -------------------------------------------------------------------------
    // Stop
    // -------------------------------------------------------------------------

    #[Test]
    public function it_stops_a_recurring_schedule(): void
    {
        /* Arrange */
        $id = $this->seedRecurring(null, ['recur_next_date' => date('Y-m-d', strtotime('+1 month'))]);

        /* Act */
        $response = $this->post('/invoices/recurring/stop/' . $id, []);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'Stopping a schedule redirects to the recurring list.');
        $this->assertDatabaseHas('ip_invoices_recurring', ['invoice_recurring_id' => $id, 'recur_next_date' => null]);
    }

    // -------------------------------------------------------------------------
    // Delete — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function it_deletes_a_recurring_schedule(): void
    {
        /* Arrange */
        $id   = $this->seedRecurring();
        $keep = $this->seedRecurring();

        /* Act */
        $response = $this->post('/invoices/recurring/delete/' . $id, []);

        /* Assert */
        $this->assertResponseRedirectsToRoute($response, 'invoices/recurring/index');
        $this->assertDatabaseMissing('ip_invoices_recurring', ['invoice_recurring_id' => $id]);
        $this->assertDatabaseHas('ip_invoices_recurring', ['invoice_recurring_id' => $keep]);
    }

    // -------------------------------------------------------------------------
    // Delete — CSRF regression (#1694)
    // -------------------------------------------------------------------------

    #[Test]
    public function it_still_deletes_a_recurring_schedule_when_csrf_protection_is_on_and_the_token_is_valid(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $id = $this->seedRecurring();

        /* Act */
        $response = $this->postWithValidCsrfToken('/invoices/recurring/delete/' . $id);

        /* Assert */
        self::assertTrue($response->isRedirect(), 'A valid-token delete redirects.');
        $this->assertDatabaseMissing('ip_invoices_recurring', ['invoice_recurring_id' => $id]);
    }

    #[Test]
    public function it_does_not_delete_a_recurring_schedule_when_the_csrf_token_is_missing(): void
    {
        /* Arrange */
        $this->enableCsrfProtection();
        $id = $this->seedRecurring();

        /* Act */
        $response = $this->postWithoutCsrfToken('/invoices/recurring/delete/' . $id);

        /* Assert */
        self::assertFalse($response->isRedirect(), 'A token-less delete must not reach the controller.');
        $this->assertDatabaseHas('ip_invoices_recurring', ['invoice_recurring_id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Guest access — always last
    // -------------------------------------------------------------------------

    #[Test]
    public function it_redirects_a_guest_to_login_and_leaks_no_recurring_schedule(): void
    {
        /* Arrange */
        $this->seedRecurring($this->seedInvoice($this->seedClient(), ['invoice_number' => 'INV-REC-SECRET']));
        $this->actingAsGuest();

        /* Act */
        $response = $this->get('/invoices/recurring');

        /* Assert */
        self::assertTrue($response->isRedirect(), 'Unauthenticated request must redirect to login.');
        $this->assertResponseBodyNotContains($response, 'INV-REC-SECRET');
    }

    private function seedRecurring(?int $invoiceId = null, array $overrides = []): int
    {
        $invoiceId ??= $this->seedInvoice($this->seedClient());

        return $this->databaseInsert('ip_invoices_recurring', array_merge([
            'invoice_id'       => $invoiceId,
            'recur_start_date' => date('Y-m-d'),
            'recur_next_date'  => date('Y-m-d', strtotime('+1 month')),
            'recur_frequency'  => '1',
        ], $overrides));
    }
}
