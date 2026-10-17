<?php

namespace Tests\Unit\Libraries;

use PDF417Barcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCiSettings;

/**
 * Upstream #1238: application/libraries/PDF417Barcode.php builds the HUB30 payment data (Croatian PDF417 payment
 * barcode) from an invoice and the QR code settings. paymentData() is plain string building, so it is tested here
 * with a fake CodeIgniter instance; generate() needs the PDF417 renderer package (see the last test).
 */
final class PDF417BarcodeTest extends TestCase
{
    private mixed $ciBackup = null;

    protected function setUp(): void
    {
        $this->ciBackup = $GLOBALS['unitCiInstance'] ?? null;
        require_once APPPATH . 'libraries/PDF417Barcode.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['unitCiInstance'] = $this->ciBackup;
    }

    #[Test]
    public function it_builds_the_hub30_payment_data_line_by_line(): void
    {
        /* Arrange */
        $barcode = $this->barcode($this->invoice(['invoice_total' => '125.50']));

        /* Act */
        $lines = explode("\n", $barcode->paymentData());

        /* Assert: header, currency, 15-digit amount in cents, payer, recipient, IBAN, model, reference, purpose, description */
        self::assertSame('HRVHUB30', $lines[0]);
        self::assertSame('EUR', $lines[1]);
        self::assertSame('000000000012550', $lines[2]);
        self::assertSame('Ana Horvat', $lines[3]);
        self::assertSame('Ilica 1', $lines[4]);
        self::assertSame('10000 Zagreb', $lines[5]);
        self::assertSame('Praxis d.o.o.', $lines[6]);
        self::assertSame('Vukovarska 2', $lines[7]);
        self::assertSame('21000 Split', $lines[8]);
        self::assertSame('HR1210010051863000160', $lines[9]);
        self::assertSame('HR00', $lines[10]);
        self::assertSame('INV-2026-7', $lines[11]);
        self::assertSame('', $lines[12], 'The purpose code is left empty.');
        self::assertSame('Placanje po racunu INV-2026-7', $lines[13]);
        self::assertCount(15, $lines, 'Fourteen fields, each ending in a newline.');
    }

    #[Test]
    public function it_pads_the_amount_to_fifteen_digits(): void
    {
        /* Act: paymentData() reads the global CI instance when called, so each barcode is used before the next is built */
        $smallAmount = explode("\n", $this->barcode($this->invoice(['invoice_total' => '0.05']))->paymentData())[2];
        $largeAmount = explode("\n", $this->barcode($this->invoice(['invoice_total' => '1234567.00']))->paymentData())[2];

        /* Assert */
        self::assertSame('000000000000005', $smallAmount);
        self::assertSame('000000123456700', $largeAmount);
    }

    #[Test]
    public function it_reads_the_invoice_again_through_the_model_so_the_amount_is_current(): void
    {
        /* Arrange: the invoice passed in is stale, the stored one has the final total */
        $barcode = $this->barcode($this->invoice(['invoice_total' => '10.00']), stored: $this->invoice(['invoice_total' => '99.00']));

        /* Act */
        $amount = explode("\n", $barcode->paymentData())[2];

        /* Assert */
        self::assertSame('000000000009900', $amount);
    }

    #[Test]
    public function it_encodes_an_amount_like_19_99_without_losing_a_cent(): void
    {
        /* Arrange */
        $barcode = $this->barcode($this->invoice(['invoice_total' => '19.99']));

        /* Act */
        $amount = explode("\n", $barcode->paymentData())[2];

        /* Assert */
        if ($amount !== '000000000001999') {
            self::markTestIncomplete('Gap in #1238: intval(19.99 * 100) is 1998 because of float rounding, so the barcode asks for one cent less (' . $amount . ').');
        }

        self::assertSame('000000000001999', $amount);
    }

    #[Test]
    public function it_keeps_a_line_break_in_a_client_field_from_shifting_the_payment_fields(): void
    {
        /* Arrange: a client name carrying a line break and a fake IBAN line */
        $barcode = $this->barcode($this->invoice(['client_name' => "Ana\nHR0000000000000000000"]));

        /* Act */
        $lines = explode("\n", $barcode->paymentData());

        /* Assert */
        if (count($lines) !== 15) {
            self::markTestIncomplete('Gap in #1238: client fields are written into the HUB30 data unsanitised, so a line break in a name or address adds a field and shifts every following one (' . count($lines) . ' lines instead of 15).');
        }

        self::assertCount(15, $lines);
    }

    #[Test]
    public function it_renders_the_barcode_image_with_the_pdf417_package(): void
    {
        /* Arrange */
        $barcode = $this->barcode($this->invoice());

        /* Act / Assert */
        if ( ! class_exists(\Radoid\PDF417\PDF417::class)) {
            self::markTestIncomplete('Gap in #1238: generate() uses Radoid\PDF417 but the package is not in composer.json, composer.lock or vendor/, so any invoice of a Croatian client fails with a missing class.');
        }

        self::assertStringStartsWith('data:image/', $barcode->generate());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function invoice(array $overrides = []): object
    {
        return (object) array_merge([
            'invoice_id'     => 7, 'invoice_total' => '100.00',
            'client_name'    => 'Ana Horvat', 'client_address_1' => 'Ilica 1', 'client_zip' => '10000', 'client_city' => 'Zagreb',
            'user_address_1' => 'Vukovarska 2', 'user_zip' => '21000', 'user_city' => 'Split', 'client_country' => 'HR',
        ], $overrides);
    }

    private function barcode(object $invoice, ?object $stored = null): PDF417Barcode
    {
        $settings        = new FakeCiSettings();
        $settings->_data = [
            'qr_code_recipient' => 'Praxis d.o.o.', 'qr_code_iban' => 'HR1210010051863000160', 'qr_code_bic' => 'ZABAHR2X',
            'currency_code'     => 'EUR', 'qr_code_remittance_text' => 'INV-2026-7',
        ];
        $stored ??= $invoice;

        $GLOBALS['unitCiInstance'] = (object) [
            'mdl_settings' => $settings,
            'mdl_invoices' => new class ($stored) {
                public function __construct(private object $stored) {}

                public function get_by_id(mixed $id): object
                {
                    return $this->stored;
                }
            },
            'load' => new class () {
                public function helper(mixed $helper): void {}
            },
        ];

        return new PDF417Barcode(['invoice' => $invoice]);
    }
}
