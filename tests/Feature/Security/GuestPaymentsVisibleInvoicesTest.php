<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * GHSA-4gpg-v8r4-53x5: the guest payment history listed every payment of the guest's clients, including payments
 * on a draft invoice whose detail page answers 404 for that same guest. A payment is shown only when its invoice
 * is one the guest may see (sent, viewed or paid), the rule Mdl_Invoices::guest_visible() applies everywhere else.
 */
#[Group('security')]
final class GuestPaymentsVisibleInvoicesTest extends AbstractTestCase
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function hiddenStatuses(): array
    {
        return [
            'draft'               => [1],
            'unknown status zero' => [0],
            'unknown status five' => [5],
        ];
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function visibleStatuses(): array
    {
        return [
            'sent'   => [2],
            'viewed' => [3],
            'paid'   => [4],
        ];
    }

    #[Test]
    #[DataProvider('hiddenStatuses')]
    public function it_hides_a_payment_on_an_invoice_the_guest_may_not_see(int $statusId): void
    {
        /* Arrange */
        $clientId  = $this->seedClient();
        $hiddenId  = $this->seedInvoice($clientId, ['invoice_status_id' => $statusId, 'invoice_number' => 'INV-HIDDEN-7731']);
        $visibleId = $this->seedInvoice($clientId, ['invoice_status_id' => 2, 'invoice_number' => 'INV-SENT-7732']);
        $this->seedPayment($hiddenId, ['payment_note' => 'hidden-payment-note']);
        $this->seedPayment($visibleId, ['payment_note' => 'sent-payment-note']);
        $this->actingAsGuestOf($clientId);
        $paymentsBefore = $this->databaseCount('ip_payments');

        /* Act */
        $response = $this->get('/guest/payments/index');

        /* Assert: nothing of the hidden invoice or its payment is in the page */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyNotContains($response, 'INV-HIDDEN-7731');
        $this->assertResponseBodyNotContains($response, 'hidden-payment-note');
        $this->assertResponseBodyNotContains($response, 'guest/invoices/view/' . $hiddenId . '"');

        /* Assert: the same client's visible invoice is still listed, so the page is not simply empty */
        $this->assertResponseBodyContains($response, 'INV-SENT-7732');
        $this->assertResponseBodyContains($response, 'sent-payment-note');
        $this->assertResponseBodyContains($response, 'guest/invoices/view/' . $visibleId . '"');

        /* Assert: a listing writes nothing */
        self::assertSame($paymentsBefore, $this->databaseCount('ip_payments'));
        $this->assertDatabaseHas('ip_invoices', ['invoice_id' => $hiddenId, 'invoice_status_id' => $statusId]);
    }

    #[Test]
    #[DataProvider('visibleStatuses')]
    public function it_lists_a_payment_on_an_invoice_the_guest_may_see(int $statusId): void
    {
        /* Arrange */
        $clientId  = $this->seedClient();
        $invoiceId = $this->seedInvoice($clientId, ['invoice_status_id' => $statusId, 'invoice_number' => 'INV-SHOWN-88' . $statusId]);
        $this->seedPayment($invoiceId, ['payment_note' => 'shown-payment-note-' . $statusId]);
        $this->actingAsGuestOf($clientId);

        /* Act */
        $response = $this->get('/guest/payments/index');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'INV-SHOWN-88' . $statusId);
        $this->assertResponseBodyContains($response, 'shown-payment-note-' . $statusId);
    }

    #[Test]
    public function it_hides_the_draft_payment_on_every_page_of_the_listing(): void
    {
        /* Arrange */
        $clientId = $this->seedClient();
        $draftId  = $this->seedInvoice($clientId, ['invoice_status_id' => 1, 'invoice_number' => 'INV-HIDDEN-9001']);
        $this->seedPayment($draftId, ['payment_note' => 'hidden-paged-note']);
        $this->actingAsGuestOf($clientId);

        foreach (['/guest/payments', '/guest/payments/index', '/guest/payments/index/0', '/guest/payments/INDEX'] as $uri) {
            /* Act */
            $response = $this->get($uri);

            /* Assert */
            $this->assertResponseBodyNotContains($response, 'INV-HIDDEN-9001');
            $this->assertResponseBodyNotContains($response, 'hidden-paged-note');
        }
    }

    #[Test]
    public function it_still_hides_another_clients_payment_on_a_visible_invoice(): void
    {
        /* Arrange: the status rule is added to the client rule, it does not replace it */
        $ownClientId   = $this->seedClient();
        $otherClientId = $this->seedClient();
        $ownId         = $this->seedInvoice($ownClientId, ['invoice_status_id' => 2, 'invoice_number' => 'INV-OWN-5101']);
        $otherId       = $this->seedInvoice($otherClientId, ['invoice_status_id' => 2, 'invoice_number' => 'INV-OTHER-5102']);
        $this->seedPayment($ownId, ['payment_note' => 'own-client-note']);
        $this->seedPayment($otherId, ['payment_note' => 'other-client-note']);
        $this->actingAsGuestOf($ownClientId);

        /* Act */
        $response = $this->get('/guest/payments/index');

        /* Assert */
        $this->assertResponseBodyContains($response, 'INV-OWN-5101');
        $this->assertResponseBodyNotContains($response, 'INV-OTHER-5102');
        $this->assertResponseBodyNotContains($response, 'other-client-note');
    }

    #[Test]
    public function it_keeps_listing_draft_invoice_payments_for_an_administrator(): void
    {
        /* Arrange: the rule is for guests only; the admin payment list is unchanged */
        $draftId = $this->seedInvoice($this->seedClient(), ['invoice_status_id' => 1, 'invoice_number' => 'INV-ADMIN-6201']);
        $this->seedPayment($draftId, ['payment_note' => 'admin-sees-draft-note']);
        $this->actingAsAdmin();

        /* Act */
        $response = $this->get('/payments/index');

        /* Assert */
        $this->assertResponseStatusCode($response, 200);
        $this->assertResponseBodyContains($response, 'INV-ADMIN-6201');
    }

    private function actingAsGuestOf(int $clientId): void
    {
        $guestUserId = $this->databaseInsert('ip_users', [
            'user_name'          => 'Guest Payments Scope',
            'user_email'         => 'guest-scope-' . bin2hex(random_bytes(4)) . '@test.local',
            'user_password'      => password_hash('secret', PASSWORD_DEFAULT),
            'user_psalt'         => bin2hex(random_bytes(10)),
            'user_type'          => 2,
            'user_active'        => 1,
            'user_date_created'  => date('Y-m-d H:i:s'),
            'user_date_modified' => date('Y-m-d H:i:s'),
        ]);

        $this->databaseInsert('ip_user_clients', ['user_id' => $guestUserId, 'client_id' => $clientId]);

        $this->actingAs([
            'user_id'       => $guestUserId,
            'user_type'     => 2,
            'user_email'    => 'guest-scope@test.local',
            'user_name'     => 'Guest Payments Scope',
            'user_company'  => '',
            'user_language' => 'system',
        ]);
    }
}
