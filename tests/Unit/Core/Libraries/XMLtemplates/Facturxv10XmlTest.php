<?php

namespace Tests\Unit\Core\Libraries\XMLtemplates;

use DOMDocument;
use DOMXPath;
use Facturxv10Xml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeCiSettings;

final class Facturxv10XmlTest extends TestCase
{
    private const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

    private mixed $ciBackup = null;

    private string $filename = '';

    protected function setUp(): void
    {
        require_once APPPATH . 'helpers/echo_helper.php';
        require_once APPPATH . 'helpers/settings_helper.php';
        require_once APPPATH . 'helpers/trans_helper.php';
        require_once APPPATH . 'libraries/XMLtemplates/Facturxv10Xml.php';

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
        $this->filename = 'facturx-test-' . bin2hex(random_bytes(6));
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
    public function it_leaves_out_the_tax_registration_of_a_buyer_without_a_vat_id(mixed $vatId): void
    {
        /* Arrange */
        $invoice = $this->invoice(['client_vat_id' => $vatId]);

        /* Act */
        $xpath = $this->generate($invoice);

        /* Assert */
        self::assertSame(0, $xpath->query('//ram:BuyerTradeParty/ram:SpecifiedTaxRegistration')->length, 'BT-48 is optional: no block without a VAT ID.');
        self::assertSame([], $this->emptyIdentifiers($xpath), 'An empty ram:ID breaks EN 16931 BR-CO-9.');
        self::assertSame(['DE123456789'], $this->vatRegistrations($xpath, 'SellerTradeParty'), 'The seller keeps its own registration.');
        self::assertSame('Buyer GmbH', $xpath->evaluate('string(//ram:BuyerTradeParty/ram:Name)'), 'The rest of the buyer is still written.');
    }

    #[Test]
    public function it_writes_the_trimmed_vat_id_of_a_buyer_that_has_one(): void
    {
        /* Arrange */
        $invoice = $this->invoice(['client_vat_id' => ' DE987654321 ']);

        /* Act */
        $xpath = $this->generate($invoice);

        /* Assert */
        self::assertSame(['DE987654321'], $this->vatRegistrations($xpath, 'BuyerTradeParty'));
        self::assertSame(['DE123456789'], $this->vatRegistrations($xpath, 'SellerTradeParty'), 'Each party gets its own identifier.');
    }

    #[Test]
    #[DataProvider('missingVatIds')]
    public function it_leaves_out_the_tax_registration_of_a_seller_without_a_vat_id(mixed $vatId): void
    {
        /* Arrange */
        $invoice = $this->invoice(['user_vat_id' => $vatId]);

        /* Act */
        $xpath = $this->generate($invoice);

        /* Assert */
        self::assertSame(0, $xpath->query('//ram:SellerTradeParty/ram:SpecifiedTaxRegistration')->length);
        self::assertSame([], $this->emptyIdentifiers($xpath));
        self::assertSame(['DE987654321'], $this->vatRegistrations($xpath, 'BuyerTradeParty'), 'The buyer keeps its own registration.');
    }

    #[Test]
    public function it_writes_only_the_seller_registration_in_the_minimum_profile(): void
    {
        /* Arrange */
        $invoice = $this->invoice();
        $options = ['GuidelineSpecifiedDocumentContextParameterID' => 'urn:factur-x.eu:1p0:minimum'];

        /* Act */
        $xpath = $this->generate($invoice, $options);

        /* Assert */
        self::assertSame(['DE123456789'], $this->vatRegistrations($xpath, 'SellerTradeParty'));
        self::assertSame([], $this->vatRegistrations($xpath, 'BuyerTradeParty'));
    }

    #[Test]
    public function it_writes_the_tax_basis_total_in_the_minimum_profile(): void
    {
        /* Arrange */
        $invoice = $this->invoice();
        $options = ['GuidelineSpecifiedDocumentContextParameterID' => 'urn:factur-x.eu:1p0:minimum'];

        /* Act */
        $xpath = $this->generate($invoice, $options);

        /* Assert */
        $summation = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        self::assertSame('100.00', $xpath->evaluate("string({$summation}/ram:TaxBasisTotalAmount)"), 'The net amount, not the gross total.');
        self::assertSame('119.00', $xpath->evaluate("string({$summation}/ram:GrandTotalAmount)"));
        self::assertSame(0, $xpath->query("{$summation}/ram:LineTotalAmount")->length, 'MINIMUM has no line total.');
    }

    /** @param array<string, mixed> $options */
    private function generate(object $invoice, array $options = ['GuidelineSpecifiedDocumentContextParameterID' => 'urn:cen.eu:en16931:2017']): DOMXPath
    {
        $generator = new Facturxv10Xml([
            'invoice'  => $invoice,
            'items'    => [$this->item()],
            'filename' => $this->filename,
            'options'  => $options,
        ]);
        $generator->xml();

        $doc = new DOMDocument();
        self::assertTrue($doc->load(UPLOADS_TEMP_FOLDER . $this->filename . '.xml'), 'The generator must write well-formed XML.');
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('ram', self::RAM);

        return $xpath;
    }

    /** @return list<string> */
    private function vatRegistrations(DOMXPath $xpath, string $party): array
    {
        $values = [];
        foreach ($xpath->query("//ram:{$party}/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']") as $node) {
            $values[] = $node->textContent;
        }

        return $values;
    }

    /** @return list<string> */
    private function emptyIdentifiers(DOMXPath $xpath): array
    {
        $paths = [];
        foreach ($xpath->query('//ram:SpecifiedTaxRegistration/ram:ID[normalize-space(.) = ""]') as $node) {
            $paths[] = $node->getNodePath();
        }

        return $paths;
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
            'user_iban'                => '',
            'user_bank'                => '',
            'client_id'                => 7,
            'client_name'              => 'Buyer GmbH',
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
