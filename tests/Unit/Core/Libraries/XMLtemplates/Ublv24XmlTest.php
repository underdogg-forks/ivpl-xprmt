<?php

namespace Tests\Unit\Core\Libraries\XMLtemplates;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCiSettings;
use Ublv24Xml;

final class Ublv24XmlTest extends TestCase
{
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private mixed $ciBackup = null;

    private string $filename = '';

    protected function setUp(): void
    {
        require_once APPPATH . 'helpers/echo_helper.php';
        require_once APPPATH . 'helpers/settings_helper.php';
        require_once APPPATH . 'helpers/trans_helper.php';
        require_once APPPATH . 'libraries/XMLtemplates/Ublv24Xml.php';

        $this->ciBackup            = $GLOBALS['unitCiInstance'] ?? null;
        $settings                  = new FakeCiSettings();
        $settings->_data           = ['currency_code' => 'EUR'];
        $GLOBALS['unitCiInstance'] = (object) [
            'mdl_settings' => $settings,
            'lang'         => new class () {
                public function line(string $key): string
                {
                    return $key;
                }
            },
        ];
        $this->filename = 'ubl-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $GLOBALS['unitCiInstance'] = $this->ciBackup;
        @unlink(UPLOADS_TEMP_FOLDER . $this->filename . '.xml');
    }

    /** @return array<string, array{0: mixed}> */
    public static function missingVatIds(): array
    {
        return [
            'empty string' => [''],
            'null'         => [null],
            'only spaces'  => ['   '],
        ];
    }

    #[Test]
    #[DataProvider('missingVatIds')]
    public function it_leaves_out_the_tax_scheme_of_a_buyer_without_a_vat_id(mixed $vatId): void
    {
        /* Arrange */
        $invoice = $this->invoice(['client_vat_id' => $vatId]);

        /* Act */
        $xpath = $this->generate($invoice);

        /* Assert */
        self::assertSame(0, $xpath->query('//cac:AccountingCustomerParty//cac:PartyTaxScheme')->length, 'BT-48 is optional: no block without a VAT ID.');
        self::assertSame(0, $xpath->query('//cac:PartyTaxScheme/cbc:CompanyID[normalize-space(.) = ""]')->length, 'An empty cbc:CompanyID breaks EN 16931 BR-CO-9.');
        self::assertSame('DE123456789', $xpath->evaluate('string(//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID)'), 'The seller keeps its own tax scheme.');
        self::assertSame('Buyer GmbH', $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyName/cbc:Name)'), 'The rest of the buyer is still written.');
    }

    #[Test]
    public function it_writes_the_tax_scheme_of_each_party_that_has_a_vat_id(): void
    {
        /* Arrange */
        $invoice = $this->invoice();

        /* Act */
        $xpath = $this->generate($invoice);

        /* Assert */
        self::assertSame('DE987654321', $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID)'));
        self::assertSame('DE123456789', $xpath->evaluate('string(//cac:AccountingSupplierParty//cac:PartyTaxScheme/cbc:CompanyID)'));
        self::assertSame('VAT', $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyTaxScheme/cac:TaxScheme/cbc:ID)'));
    }

    #[Test]
    #[DataProvider('missingVatIds')]
    public function it_leaves_out_the_tax_scheme_of_a_seller_without_a_vat_id(mixed $vatId): void
    {
        /* Arrange */
        $invoice = $this->invoice(['user_vat_id' => $vatId]);

        /* Act */
        $xpath = $this->generate($invoice);

        /* Assert */
        self::assertSame(0, $xpath->query('//cac:AccountingSupplierParty//cac:PartyTaxScheme')->length);
        self::assertSame('DE987654321', $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID)'), 'The buyer keeps its own tax scheme.');
    }

    private function generate(object $invoice): DOMXPath
    {
        $generator = new Ublv24Xml([
            'invoice'  => $invoice,
            'items'    => [$this->item()],
            'filename' => $this->filename,
            'options'  => [],
        ]);
        $generator->xml();

        $doc = new DOMDocument();
        self::assertTrue($doc->load(UPLOADS_TEMP_FOLDER . $this->filename . '.xml'), 'The generator must write well-formed XML.');
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('cac', self::CAC);
        $xpath->registerNamespace('cbc', self::CBC);

        return $xpath;
    }

    /** @param array<string, mixed> $overrides */
    private function invoice(array $overrides = []): object
    {
        return (object) array_merge([
            'invoice_number'           => 'RE-2026-001',
            'invoice_date_created'     => '2026-11-01',
            'invoice_date_due'         => '2026-11-30',
            'invoice_terms'            => '',
            'invoice_discount_amount'  => 0,
            'invoice_discount_percent' => 0,
            'invoice_item_subtotal'    => 100.00,
            'invoice_item_tax_total'   => 19.00,
            'invoice_tax_total'        => 0.00,
            'invoice_total'            => 119.00,
            'invoice_paid'             => 0.00,
            'invoice_balance'          => 119.00,
            'user_id'                  => 1,
            'user_name'                => 'Seller GmbH',
            'user_company'             => 'Seller GmbH',
            'user_email'               => 'seller@example.de',
            'user_zip'                 => '10115',
            'user_address_1'           => 'Hauptstrasse 1',
            'user_address_2'           => '',
            'user_city'                => 'Berlin',
            'user_country'             => 'DE',
            'user_vat_id'              => 'DE123456789',
            'user_tax_code'            => '',
            'user_einvoice_identifier' => '',
            'user_invoicing_contact'   => '',
            'user_phone'               => '',
            'user_iban'                => '',
            'user_bank'                => '',
            'client_id'                => 7,
            'client_name'              => 'Buyer GmbH',
            'client_company'           => '',
            'client_invoicing_contact' => '',
            'client_phone'             => '',
            'client_email'             => 'buyer@example.de',
            'client_peppol_id'         => '',
            'client_zip'               => '80331',
            'client_address_1'         => 'Marktplatz 2',
            'client_address_2'         => '',
            'client_city'              => 'Muenchen',
            'client_country'           => 'DE',
            'client_vat_id'            => 'DE987654321',
            'client_tax_code'          => '',
        ], $overrides);
    }

    private function item(): object
    {
        return (object) [
            'item_name'             => 'Beratung',
            'item_description'      => '',
            'item_order'            => 1,
            'item_quantity'         => 1,
            'item_price'            => 100.00,
            'item_product_unit'     => '',
            'item_discount'         => 0.00,
            'item_discount_amount'  => 0.00,
            'item_subtotal'         => 100.00,
            'item_tax_rate_percent' => 19.00,
            'item_tax_total'        => 19.00,
            'item_total'            => 119.00,
        ];
    }
}
