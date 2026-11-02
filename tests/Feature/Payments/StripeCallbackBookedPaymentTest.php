<?php

namespace Tests\Feature\Payments;

use Cryptor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\AbstractTestCase;

/**
 * Once Stripe::callback() has booked a payment, nothing that goes wrong afterwards may tell the customer it
 * failed (a customer told so pays again). And money taken at Stripe that was not booked here, for instance a
 * second charge on an invoice that is already paid, must be flagged in the error log for manual reconciliation,
 * as the PayPal callback does.
 */
#[Group('feature')]
#[Group('payments')]
final class StripeCallbackBookedPaymentTest extends AbstractTestCase
{
    private const ENCRYPTION_KEY = '0123456789abcdef0123456789abcdef';

    private const MARKER = 'succeeded but was not recorded against invoice';

    protected function setUp(): void
    {
        parent::setUp();

        require_once ROOT_PATH . '/application/libraries/Cryptor.php';
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_stripe_apiKey', 'setting_value' => Cryptor::Encrypt('sk_test_fake_key', self::ENCRYPTION_KEY)]);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_stripe_currency', 'setting_value' => 'EUR']);
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'gateway_stripe_payment_method', 'setting_value' => '1']);
    }

    #[Test]
    public function it_still_tells_the_customer_the_payment_worked_when_describing_a_booked_payment_fails(): void
    {
        /* Arrange: a translation of the success message with one placeholder too many; sprintf() throws on it after the payment is booked */
        $language = 'zz_bad_translation';
        $this->installLanguage($language, "<?php\n\$lang['online_payment_successful'] = 'Payment %s for invoice %s';\n");
        $this->databaseInsertOrIgnore('ip_settings', ['setting_key' => 'default_language', 'setting_value' => $language]);
        $this->databaseUpdate('ip_settings', ['setting_value' => $language], ['setting_key' => 'default_language']);
        $invoiceId = $this->seedPayableInvoice();
        $urlKey    = $this->urlKeyOf($invoiceId);
        $number    = (string) $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId])['invoice_number'];
        $this->mockStripe($this->session($urlKey, 'pi_booked_then_boom', 5000));
        [$logFile, $offset] = $this->logPosition();

        try {
            /* Act */
            $response = $this->get('/guest/gateways/stripe/callback/cs_test_callback');
        } finally {
            $this->removeLanguage($language);
        }

        /* Assert: booked, reported as a success, and the failure is in the log rather than in front of the customer */
        $this->assertDatabaseHas('ip_payments', ['invoice_id' => $invoiceId, 'payment_external_id' => 'pi_booked_then_boom', 'payment_amount' => '50.00']);
        self::assertStringContainsString('#' . $number, (string) $response->sessionValue('alert_success'));
        self::assertNull($response->sessionValue('alert_error'), 'A booked payment must never be reported as an error.');
        $logged = $this->logSince($logFile, $offset);
        self::assertStringContainsString('(payment already recorded)', $logged);
        self::assertStringNotContainsString(self::MARKER, $logged, 'A booked payment needs no reconciliation.');
    }

    #[Test]
    public function it_flags_a_second_charge_on_an_already_paid_invoice_for_manual_reconciliation(): void
    {
        /* Arrange: the customer was told it failed, paid again, and Stripe took the money a second time */
        $invoiceId = $this->seedInvoice($this->seedClient(), ['invoice_status_id' => 4], ['invoice_total' => '50.00', 'invoice_paid' => '50.00', 'invoice_balance' => '0.00']);
        $urlKey    = $this->urlKeyOf($invoiceId);
        $number    = (string) $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId])['invoice_number'];
        $this->mockStripe($this->session($urlKey, 'pi_second_charge', 5000));
        [$logFile, $offset] = $this->logPosition();

        /* Act */
        $response = $this->get('/guest/gateways/stripe/callback/cs_test_callback');

        /* Assert: the customer is told, nothing is booked twice, and the charge is flagged exactly once for a refund */
        self::assertSame('This invoice was already paid.', $response->sessionValue('alert_info'));
        self::assertSame(0, $this->databaseCount('ip_payments', ['payment_external_id' => 'pi_second_charge']));
        $logged = $this->logSince($logFile, $offset);
        self::assertSame(1, substr_count($logged, self::MARKER), 'The reconciliation line must be written exactly once.');
        self::assertStringContainsString('Stripe payment intent pi_second_charge ' . self::MARKER . ' ' . $number, $logged);
        $row = $this->databaseFetchOne('ip_merchant_responses', ['invoice_id' => $invoiceId]);
        self::assertStringContainsString('paid at Stripe, NOT recorded locally', (string) $row['merchant_response']);
    }

    #[Test]
    public function it_flags_a_charge_that_is_smaller_than_the_balance_for_manual_reconciliation(): void
    {
        /* Arrange: Stripe took 10.00 for an invoice that still owes 50.00, so it is refused */
        $invoiceId = $this->seedPayableInvoice();
        $urlKey    = $this->urlKeyOf($invoiceId);
        $this->mockStripe($this->session($urlKey, 'pi_too_small', 1000));
        [$logFile, $offset] = $this->logPosition();

        /* Act */
        $response = $this->get('/guest/gateways/stripe/callback/cs_test_callback');

        /* Assert */
        self::assertSame('Payment failed. Please try again.', $response->sessionValue('alert_info'));
        self::assertSame(0, $this->databaseCount('ip_payments', ['payment_external_id' => 'pi_too_small']));
        self::assertSame(1, substr_count($this->logSince($logFile, $offset), self::MARKER));
    }

    #[Test]
    public function it_does_not_flag_a_payment_that_was_booked(): void
    {
        /* Arrange: positive control, the ordinary paid callback */
        $invoiceId = $this->seedPayableInvoice();
        $urlKey    = $this->urlKeyOf($invoiceId);
        $this->mockStripe($this->session($urlKey, 'pi_fine', 5000));
        [$logFile, $offset] = $this->logPosition();

        /* Act */
        $response = $this->get('/guest/gateways/stripe/callback/cs_test_callback');

        /* Assert */
        self::assertNotNull($response->sessionValue('alert_success'));
        $this->assertDatabaseHas('ip_payments', ['payment_external_id' => 'pi_fine']);
        self::assertStringNotContainsString(self::MARKER, $this->logSince($logFile, $offset));
        $row = $this->databaseFetchOne('ip_merchant_responses', ['invoice_id' => $invoiceId]);
        self::assertStringNotContainsString('NOT recorded locally', (string) $row['merchant_response']);
    }

    #[Test]
    public function it_does_not_flag_the_replay_of_a_payment_intent_that_is_already_booked(): void
    {
        /* Arrange: the customer reloads the return page; the intent is already on a payment row */
        $invoiceId = $this->seedPayableInvoice();
        $this->seedPayment($invoiceId, ['payment_external_id' => 'pi_replayed', 'payment_amount' => '50.00']);
        $urlKey = $this->urlKeyOf($invoiceId);
        $this->mockStripe($this->session($urlKey, 'pi_replayed', 5000));
        [$logFile, $offset] = $this->logPosition();

        /* Act */
        $response = $this->get('/guest/gateways/stripe/callback/cs_test_callback');

        /* Assert */
        self::assertSame('This payment has already been processed and recorded.', $response->sessionValue('alert_info'));
        self::assertSame(1, $this->databaseCount('ip_payments', ['payment_external_id' => 'pi_replayed']));
        self::assertStringNotContainsString(self::MARKER, $this->logSince($logFile, $offset));
    }

    private function installLanguage(string $language, string $ipLang): void
    {
        $dir = APPPATH . 'language/' . $language;
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/ip_lang.php', $ipLang);
        file_put_contents($dir . '/form_validation_lang.php', "<?php\n\$lang = [];\n");
        copy(APPPATH . 'language/english/gateway_lang.php', $dir . '/gateway_lang.php');
    }

    private function removeLanguage(string $language): void
    {
        $dir = APPPATH . 'language/' . $language;
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($dir);
    }

    private function seedPayableInvoice(): int
    {
        return $this->seedInvoice($this->seedClient(), ['invoice_status_id' => 2], ['invoice_balance' => '50.00']);
    }

    private function urlKeyOf(int $invoiceId): string
    {
        return (string) $this->databaseFetchOne('ip_invoices', ['invoice_id' => $invoiceId])['invoice_url_key'];
    }

    private function mockStripe(array $responses): void
    {
        $this->withEnvironment(['STRIPE_MOCK_RESPONSES' => json_encode($responses)]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function session(string $urlKey, string $paymentIntent, int $amountMinor, array $overrides = []): array
    {
        return [['status' => 200, 'body' => json_encode(array_merge([
            'id'                  => 'cs_test_callback',
            'object'              => 'checkout.session',
            'status'              => 'complete',
            'mode'                => 'payment',
            'payment_status'      => 'paid',
            'client_reference_id' => $urlKey,
            'payment_intent'      => $paymentIntent,
            'currency'            => 'eur',
            'amount_total'        => $amountMinor,
            'livemode'            => false,
        ], $overrides))]];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function logPosition(): array
    {
        $logFile = APPPATH . 'logs/log-' . date('Y-m-d') . '.php';

        return [$logFile, is_file($logFile) ? (int) filesize($logFile) : 0];
    }

    private function logSince(string $logFile, int $offset): string
    {
        clearstatcache(true, $logFile);

        return is_file($logFile) ? (string) file_get_contents($logFile, false, null, $offset) : '';
    }
}
